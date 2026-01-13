<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;


class ProfileController extends Controller
{
    public function profile(): JsonResponse
    {
        try{
            $user = Auth::user();
            return response()->json(['user' => $user], 200);
        } catch (Exception $e) {
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    public function updateProfile(Request $request): JsonResponse
    {
        try{
            $user = Auth::user();
            
            $validator = Validator::make($request->all(), [
                'name' => 'required|string',
                'email' => 'required|email|unique:users,email,'.$user->id,
                'phone' => 'nullable|string',
            ],[
                'email.unique' => 'The email has already been taken.',
                'email.email' => 'The email must be a valid email address.',
                'name.required' => 'The name field is required.',
                'name.string' => 'The name must be a string.',
                'phone.string' => 'The phone must be a string.',
            ]);

            if ($validator->fails()) throw new Exception($validator->errors()->first(), 400);

            $user->update([
                'name' => $request->name,
                'email' => $request->email,
                'phone' => $request->phone,
            ]);
            
            return response()->json(['message' => 'Profile updated successfully.', 'user' => $user], 200);
        } catch (Exception $e) {
            return response()->json(['message' => 'Failed to update profile, please try again later.'], 500);
        }
    }

    public function checkPlan(Request $request): JsonResponse
    {
        try{
            $user = Auth::user();
            $plan = $user->subscription()->where('status', 'active')->first()->plan ?? 'Free';
            return response()->json(['plan' => $plan], 200);
        }catch(Exception $e){
            return response()->json(['error', $e->getMessage()], $e->getCode() ?: 500);
        }
    }
}
    