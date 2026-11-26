<?php

namespace App\Domain\Auth\Middleware;

use App\Domain\Auth\Models\User;
use App\Support\ErrorCode;
use App\Support\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new ApiException(
                errorCode: ErrorCode::UNAUTHENTICATED,
                message: 'Autentikasi diperlukan.',
                httpStatus: 401,
            );
        }

        if (! $user->isActive()) {
            throw new ApiException(
                errorCode: ErrorCode::FORBIDDEN,
                message: 'Akun Anda tidak aktif atau ditangguhkan.',
                httpStatus: 403,
            );
        }

        if ($roles !== [] && ! in_array($user->role->value, $roles, true)) {
            throw new ApiException(
                errorCode: ErrorCode::FORBIDDEN,
                message: 'Anda tidak memiliki hak akses untuk peran ini.',
                httpStatus: 403,
            );
        }

        return $next($request);
    }
}
