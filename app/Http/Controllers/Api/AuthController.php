<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Deteksi NIU: terdaftar? sudah aktivasi? → menentukan UI login vs aktivasi.
     */
    public function checkNiu(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'niu' => ['required', 'string', 'max:50'],
        ]);

        $niu = trim($validated['niu']);
        $user = User::where('niu', $niu)->first();

        if (! $user) {
            return response()->json([
                'status' => 'not_found',
                'message' => 'NIU tidak terdaftar. Hubungi Komti Kelas.',
            ], 404);
        }

        return response()->json([
            'status' => $user->isUsable() ? 'ready_to_login' : 'needs_activation',
            'user' => $this->userBrief($user),
        ]);
    }

    /**
     * Aktivasi PIN pertama kali untuk akun baru.
     */
    public function activatePin(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'niu' => ['required', 'string', 'exists:users,niu'],
            'pin' => ['required', 'string', 'regex:/^[0-9]{6}$/'],
            'pin_confirmation' => ['required', 'same:pin'],
        ], [
            'pin.regex' => 'PIN harus berupa 6 digit angka.',
            'pin_confirmation.same' => 'Konfirmasi PIN tidak cocok.',
        ]);

        $user = User::where('niu', $validated['niu'])->firstOrFail();

        if ($user->isUsable()) {
            throw ValidationException::withMessages([
                'niu' => 'Akun ini sudah aktif. Silakan masuk dengan PIN Anda.',
            ]);
        }

        $user->update([
            'pin_hash' => Hash::make($validated['pin']),
            'is_active' => true,
        ]);

        Auth::login($user, true);
        $request->session()->regenerate();

        return response()->json([
            'message' => 'Akun berhasil diaktifkan! Selamat datang.',
            'user' => $this->userFull($user),
        ]);
    }

    /**
     * Login NIU + PIN.
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'niu' => ['required', 'string'],
            'pin' => ['required', 'string', 'regex:/^[0-9]{6}$/'],
        ], [
            'pin.regex' => 'PIN harus terdiri dari 6 digit angka.',
        ]);

        $user = User::where('niu', trim($validated['niu']))->first();

        if (! $user || empty($user->pin_hash) || ! Hash::check($validated['pin'], $user->pin_hash)) {
            throw ValidationException::withMessages([
                'pin' => 'PIN yang Anda masukkan salah.',
            ]);
        }

        Auth::login($user, true);
        $request->session()->regenerate();

        return response()->json([
            'message' => 'Berhasil masuk.',
            'user' => $this->userFull($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $this->userFull($request->user()),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Anda telah keluar.']);
    }

    private function userBrief(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'niu' => $user->niu,
            'role' => $user->role,
            'theory_class' => $user->theory_class,
            'practicum_group' => $user->practicum_group,
        ];
    }

    private function userFull(User $user): array
    {
        return [
            ...$this->userBrief($user),
            'is_admin' => $user->isAdmin(),
            'is_pj' => $user->isPj(),
        ];
    }
}
