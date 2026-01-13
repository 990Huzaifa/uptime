<?php

namespace App\Http\Controllers;

use App\Mail\VerifyAccountMail;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\User;
use Exception;
use Hash;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Mail;
use App\Mail\OTPMail;
use Illuminate\Support\Facades\DB;
use App\Models\PasswordResetToken;
use App\Services\BrevoService;
use App\Services\SmsService;
use Log;

class AuthController extends Controller
{
    public function signup(Request $request): JsonResponse
    {
        try {
            DB::beginTransaction();
            $validator = Validator::make($request->all(), [
                'name' => 'required',
                'email' => 'required|email|unique:users,email',
                'password' => 'required',
                'device_id' => 'required',
                'fcm_id' => 'required',
                'app_version' => 'required|string',
            ], [
                'name.required' => 'Name is required',
                'email.required' => 'Email is required',
                'email.email' => 'Invalid email format',
                'email.unique' => 'Email already exists',
                'password.required' => 'Password is required',
                'device_id.required' => 'Device ID is required',
                'fcm_id.required' => 'FCM Token is required',
            ]);

            if ($validator->fails()) throw new Exception($validator->errors()->first(), 400);


            $token = rand(1000, 9999);
            $data = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'status' => 'active',
                'device_id' => $request->device_id,
                'fcm_id' => $request->fcm_id,
                'remember_token' => $token,
            ]);

            // Mail::to($request->email)->send(new VerifyAccountMail([
            //     'message' => 'Hi ' . $data->first_name . $data->last_name . ', This is your one time password',
            //     'otp' => $token,
            //     'is_url' => false
            // ]));

            $brevo = new BrevoService();
            $htmlContent = "<html><body><p>Hi " . $data->name . ",</p><p>This is your account verification code: <strong>" . $token . "</strong></p></body></html>";
            $res = $brevo->sendMail('Account Verification Code', $data->email, $data->name, $htmlContent);

            Log::info('Brevo sendMail response: ' . print_r($res, true));
            DB::commit();
            return response()->json(['message' => 'Your account has been created successfully'], 200);
        } catch (QueryException $e) {
            DB::rollBack();
            return response()->json(['DB error' => $e->getMessage()], 403);
        } catch (Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], $e->getCode() ?: 500);
        }
    }

    public function socialLoginSignup(Request $request): JsonResponse
    {
        try{
            $validator = Validator::make($request->all(), [
                'provider' => 'required|in:google,apple,facebook',
                'email' => 'nullable|email',
                'name' => 'required',
                'device_id' => 'required',
                'google_id' => 'required_if:provider,google',
                'apple_id' => 'required_if:provider,apple',
                'facebook_id' => 'required_if:provider,facebook',
                'app_version' => 'required|string',
                'fcm_id' => 'required|string',
            ]);
            if($validator->fails()) throw new Exception($validator->errors()->first(),422);


            $user = null;
            if($request->provider == 'google'){
                $user = User::where('google_id', $request->google_id)->orWhere('email', $request->email)->first();
            }elseif($request->provider == 'apple'){
                $user = User::where('apple_id', $request->apple_id)->orWhere('email', $request->email)->first();
            }elseif($request->provider == 'facebook'){
                $user = User::where('facebook_id', $request->facebook_id)->orWhere('email', $request->email)->first();
            }


            $already_registered = false;
            if($user){
                $already_registered = true;
            }

            // if not found the register a user with the provider data
            DB::beginTransaction();
            if(!$user){
                $user = User::create([
                    'email' => $request->email,
                    'name' => $request->name,
                    'google_id' => $request->google_id ?? null,
                    'apple_id' => $request->apple_id ?? null,
                    'facebook_id' => $request->facebook_id ?? null,
                    'fcm_id' => $request->fcm_id,
                    'ip' => $request->ip(),
                    'app_version' => $request->app_version,
                    'device_id' => $request->device_id,
                ]);

            }
            // delete previous tokens
            $user->tokens()->delete();
            $token = $user->createToken('auth_token')->plainTextToken;
            $user->update([
                'google_id' => $request->google_id ?? null,
                'apple_id' => $request->apple_id ?? null,
                'facebook_id' => $request->facebook_id ?? null,
                'last_login_at' => now(),
                'fcm_id' => $request->fcm_id,
                'ip' => $request->ip(),
                'app_version' => $request->app_version,
                'device_id' => $request->device_id,
            ]);
            DB::commit();
            return response()->json(['token' => $token,'user' => $user,'already_registered' => $already_registered], 200);
        }catch(QueryException $e){
            DB::rollBack();
            return response()->json(['DB error' => $e->getMessage()], 500);
        }catch(Exception $e){
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], $e->getCode()?: 500);
        }
    }

    public function accountCheck(Request $request): JsonResponse
    {
        try{
            $validator = Validator::make($request->all(), [
                'email' => 'nullable|email',
                'social_id' => 'nullable|string',
            ]);
            if($validator->fails()) throw new Exception($validator->errors()->first(),422);

            $user = null;
            if($request->email){
                $user = User::where('email', $request->email)->first();
            }elseif($request->social_id){
                $user = User::where('google_id', $request->social_id)->first();
                if(!$user){
                    $user = User::where('apple_id', $request->social_id)->first();
                }
            }

            if(!$user) return response()->json(['user' => null], 200);
            // delete and create new token and set up last login at
            $user->tokens()->delete();
            $token = $user->createToken('auth_token')->plainTextToken;
            $user->update(['last_login_at' => now(),'fcm_id' => $request->fcm_id, 'ip' => $request->ip(),'app_version' => $request->app_version,'device_id' => $request->device_id,]);

            return response()->json(['token' => $token,'user' => $user], 200);

        }catch(Exception $e){
            return response()->json(['error' => $e->getMessage()], $e->getCode());
        }
    }


    public function verification(string $token, string $email): JsonResponse
    {
        try {
            DB::beginTransaction();
            $validator = Validator::make([
                'token' => $token,
                'email' => $email,
            ], [
                'token.required' => 'Token is required',
                'email.required' => 'Email is required',
            ]);

            $is_verify = User::where('email', $email)->first();
            if ($is_verify->email_verified_at != null)
                throw new Exception('Email already verified');
            if ($validator->fails())
                throw new Exception($validator->errors()->first(), 400);
            $user = User::where('remember_token', $token)->where('email', $email)->first();
            if (!$user)
                throw new Exception('Invalid Request');

            $user->email_verified_at = now();
            $user->remember_token = null;
            $user->save();

            DB::commit();

            return response()->json(['message' => 'Your account has been verified successfully'], 200);
        } catch (QueryException $e) {
            return response()->json(['DB error' => $e->getMessage()], 500);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function signin(Request $request):JsonResponse
    {
        try{
            $validator = Validator::make($request->all(), [
                'email' => 'required|email',
                'password' => 'required',
            ]);
            if ($validator->fails())throw new Exception($validator->errors()->first(), 422);

            // Conditions
            if (!User::where('email', $request->email)->exists())throw new Exception('Invalid email address or password', 400);

            DB::beginTransaction();
            $user = User::where('email', $request->email)->first();
            if (!Hash::check($request->password, $user->password)) throw new Exception('Invalid email address or password', 400);
            $user->tokens()->delete();
            $token = $user->createToken('auth_token')->plainTextToken; 

            $user->update([
                'fcm_id' => $request->fcm_id,
                'ip' => $request->ip(),
                'app_version' => $request->app_version,
                'last_login_at' => now(),
                'device_id' => $request->device_id,
            ]);
            DB::commit();
            return response()->json(['token' => $token, 'user' => $user], 200);
        }catch(Exception $e){
            DB::rollBack();
            return response()->json(['error', $e->getMessage()], $e->getCode() ?: 500);
        }
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        try {
            $validator = validator(
                $request->all(),
                [
                    'email' => 'required|email|exists:users',
                ],
                [
                    'email.required' => 'Email Address required',
                    'email.email' => 'Invalid Email',
                    'email.exists' => 'Invalid Email Address',
                ]
            );

            if ($validator->fails())
                throw new Exception($validator->errors()->first(), 400);

            $tokenExist = PasswordResetToken::where('email', $request->email)->exists();
            if ($tokenExist)
                PasswordResetToken::where('email', $request->email)->delete();

            //  otp 6 number
            $token = rand(1000, 9999);
            PasswordResetToken::insert([
                'email' => $request->email,
                'token' => $token,
                'created_at' => now()
            ]);

            $user = User::where('email', $request->email)->first();

            // Mail::to($request->email)->send(new OTPMail([
            //     'message' => 'Hi ' . $user->first_name . $user->last_name . 'This is your one time password',
            //     'otp' => $token,
            //     'is_url' => false
            // ],'Reset Password OTP'));

            myMailSend($request->email, $user->first_name . $user->last_name ,'Reset Password OTP', $token);
            return response()->json([
                'message' => 'Reset OTP sent successfully',
            ], 200);
        } catch (QueryException $e) {
            return response()->json(['DB error' => $e->getMessage()], 400);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], $e->getCode() ?: 500);
        }
    }

    public function resetPassword(Request $request): JsonResponse
    {
        try {
            $validator = validator(
                $request->all(),
                [
                    'token' => 'required|string',

                    'password' => 'nullable|string|min:6',
                ],
                [
                    'token.required' => 'Token required',

                    'password.string' => 'Password must be a string',
                    'password.min' => 'Password must be at least 6 characters',
                ]
            );

            if ($validator->fails())
                throw new Exception($validator->errors()->first(), 400);

            $data = PasswordResetToken::where('token', $request->token)->first();
            if (empty($data))
                throw new Exception('Invalid token', 400);

            // Phase 1: OTP Verified successfully
            if (empty($request->password)) {
                // If no password is provided, just return a success message for OTP verification
                return response()->json([
                    'message' => 'OTP verified successfully',
                ], 200);
            }

            $user = User::where('email', $data->email)->first();
            $user->update([
                'password' => Hash::make($request->password),
            ]);

            PasswordResetToken::where('token', $request->token)->delete();

            return response()->json([
                'message' => 'Password reset successfully',
            ], 200);
        } catch (QueryException $e) {
            return response()->json(['DB error' => $e->getMessage()], 400);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function resendCode(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'email' => 'required|email',
            ], [
                'email.required' => 'Email is required',
                'email.email' => 'Invalid email format',
            ]);

            if ($validator->fails())
                throw new Exception($validator->errors()->first(), 400);

            $user = User::where('email', $request->email)->first();
            if (!$user)
                throw new Exception('User not found', 404);
            $token = rand(1000, 9999);
                PasswordResetToken::where('email', $request->email)->delete();
                PasswordResetToken::insert([
                    'email' => $request->email,
                    'token' => $token,
                    'created_at' => now()
                ]);
                myMailSend($request->email, $user->first_name . $user->last_name ,'Reset Password OTP', $token);



            return response()->json(['token' => $token], 200);
        } catch (QueryException $e) {
            return response()->json(['DB error' => $e->getMessage()], 500);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function changePassword(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            DB::beginTransaction();
            $validator = Validator::make($request->all(), [
                'current_password' => 'required',
                'new_password' => 'required',
            ], [
                'current_password.required' => 'Current password is required',
                'new_password.required' => 'New password is required',
            ]);

            if ($validator->fails())
                throw new Exception($validator->errors()->first(), 400);


            if (!$user || !Hash::check($request->current_password, $user->password)) {
                return response()->json(['message' => 'Current password is mismatch'], 401);
            }
            $user->update([
                'password' => Hash::make($request->new_password),
            ]);
            DB::commit();
            return response()->json(['message' => 'Password changed successfully'], 200);
        } catch (QueryException $e) {
            DB::rollBack();
            return response()->json(['DB error' => $e->getMessage()], 500);
        } catch (Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function logout(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            $user->tokens()->delete();
            return response()->json(['message' => 'Logged out successfully'], 200);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }


    // verify phone number ()

    public function verifyPhone(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'phone' => 'required|unique:users,phone',
                'token' => 'nullable',
            ], [
                'phone.required' => 'Phone number is required',
                'phone.unique' => 'Phone number already exists',
            ]);

            if ($validator->fails())
                throw new Exception($validator->errors()->first(), 400);

            if($request->token){
                $check_user = User::where('phone', $request->phone)->where('otp', $request->token)->first();
                if(!$check_user) throw new Exception('Invalid token', 400);
                $check_user->update([
                    'otp' => null,
                    'phone_verified_at' => now(),
                ]);
                
            }else{
                $user = Auth::user();
                $token = rand(1000, 9999);
                // send otp to phone number

                $body = 'Hi ' . $user->name . ', This is your one time password: ' . $token;
                $smsService = new SmsService();
                $smsService->sendSms($request->phone, $body);
                $user->update([
                    'otp', $token,
                    'phone' => $request->phone,
                ]);
            }

            return response()->json(['message' => 'Phone number verified successfully'], 200);
        } catch (QueryException $e) {
            return response()->json(['DB error' => $e->getMessage()], 500);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

}
