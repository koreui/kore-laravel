<?php

declare(strict_types=1);

namespace App\Modules\Users\Http\Controllers;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;

final class UsersController extends Controller
{
    public function index(): View
    {
        return view('users::pages.index');
    }

    public function create(): View
    {
        return view('users::pages.create');
    }

    public function edit(User $user): View
    {
        return view('users::pages.edit', ['model' => $user]);
    }
}
