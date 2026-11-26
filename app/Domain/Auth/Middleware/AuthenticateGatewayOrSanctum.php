<?php

namespace App\Domain\Auth\Middleware;

use App\Domain\Auth\Enums\UserRole;
use App\Domain\Auth\Models\User;
use Closure;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateGatewayOrSanctum
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string ...$guards): Response
    {
        $userId = $request->header('X-User-Id');
        if (! empty($userId)) {
            $userRoleStr = $request->header('X-User-Role', 'merchant');
            $merchantId = $request->header('X-Merchant-Id');
            $supplierId = $request->header('X-Supplier-Id');

            /** @var User|null $user */
            $user = User::query()->find($userId);
            if (! $user) {
                $user = new User();
                $user->id = $userId;
                $user->role = UserRole::tryFrom($userRoleStr) ?? UserRole::MERCHANT;
                $user->status = \App\Domain\Auth\Enums\UserStatus::ACTIVE;
                $user->name = 'Gateway User';
                $user->email = 'user@petabahan.local';
                $user->phone = '628000000000';
                $user->password = '';
                $user->exists = true;
            }

            if (! empty($merchantId)) {
                $request->attributes->set('merchant_id', $merchantId);
                $user->merchant_id = $merchantId;
            }

            if (! empty($supplierId)) {
                $request->attributes->set('supplier_id', $supplierId);
                $user->supplier_id = $supplierId;
            }

            Auth::setUser($user);
            $request->setUserResolver(fn (): User => $user);

            return $next($request);
        }

        return app(Authenticate::class)->handle($request, $next, 'sanctum');
    }
}
