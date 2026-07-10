<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): View
    {
        // Defense in depth — the route only exists when the flag is on,
        // but never rely on routing alone for an access decision.
        abort_unless(config('auth.allow_registration'), 404);

        return view('auth.register');
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        abort_unless(config('auth.allow_registration'), 404);

        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'cnic' => ['nullable', 'digits:13', 'unique:'.User::class],
            'phone_number' => ['nullable', 'digits:11', 'unique:'.User::class],
            'address' => ['nullable', 'string', 'max:500'],
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'cnic' => $request->cnic,
            'phone_number' => $request->phone_number,
            'address' => $request->address,
        ]);

        // Self-serve accounts start as consumers: full own-meter dashboard,
        // rename-only editing (docs/FGAC_IMPLEMENTATION_PLAN.md §3.2).
        $user->assignRole('consumer');

        event(new Registered($user));

        Auth::login($user);

        return redirect(route('dashboard', absolute: false));
    }
}
