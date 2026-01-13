<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Exception;

class UserController extends Controller
{
    public function profile(Request $request): JsonResponse
    {
        try{
            $user = Auth::user();
            return response()->json(['user' => $user], 200);
        }catch(Exception $e){
            return response()->json(['error', $e->getMessage()], $e->getCode() ?: 500);
        }
    }

    public function update(Request $request): JsonResponse
    {
        try{
            $user = Auth::user();
            $user->name = $request->name;
            $user->email = $request->email;
            $user->phone = $request->phone;
            $user->save();
            return response()->json(['message' => 'profile updated successfully', 'user' => $user], 200);
        }catch(Exception $e){
            return response()->json(['error', $e->getMessage()], $e->getCode() ?: 500);
        }
    }

    public function updatePlan(Request $request): JsonResponse
    {
        try{
            $user = Auth::user();
            $request->validate([
                'plan' => 'required|in:Basic,Standard,Premium',
            ]);
            $user->plan = $request->plan;
            $user->save();
            return response()->json(['message' => 'Plan updated successfully', 'user' => $user], 200);
        }catch(Exception $e){
            return response()->json(['error', $e->getMessage()], $e->getCode() ?: 500);
        }
    }


}
