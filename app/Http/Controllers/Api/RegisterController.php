<?php

namespace App\Http\Controllers\Api;

use App\Constants\ApiResponseType;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class RegisterController extends Controller
{
    //
    public function login(Request $request)
    {
        $credential = $this->validate($request, [
            'email'=>['required'],
            'password'=>['required']
        ]);
        $user=User::withTrashed()->where('email', $credential['email'])->first();
        if (blank($user)) {
            return response()->json([
                'status' => 404,
            ]);
        }
        $matchedPassword = Hash::check($credential['password'], $user->password);
        if (!$matchedPassword) {
            return response()->json([
                'status' => 417,
            ]);
        }
        if (filled($user->deleted_at)) {
            return response()->json([
                'status' => 407,
            ]);
        }
        $token=($user)->createToken('personalToken')->plainTextToken;
        return response()->json([
            'status'=>200,
            'token' => $token,
            'user'   => $user->load('roles.permissions'),
        ]);
    }

    public function logout(Request $request)
    {
        (\auth()->user())->tokens()->delete();
        return response()->json([
            'status'=>200,
            'data' => true,
            'message' => 'Logout success'
        ]);
    }
}
