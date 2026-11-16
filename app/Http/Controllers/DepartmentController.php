<?php

namespace App\Http\Controllers;

use App\Models\Department;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DepartmentController extends Controller
{
    /**
     * Departments the user may file documents under: all of their
     * organization's for admins, only their own for everyone else.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $departments = Department::query()
            ->where('organization_id', $user->organization_id)
            ->when(! $user->isAdmin(), fn ($query) => $query->whereKey($user->department_id))
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json(['data' => $departments]);
    }
}
