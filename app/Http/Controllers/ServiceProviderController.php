<?php

namespace App\Http\Controllers;


use App\Models\ServiceProvider;
use App\Models\User;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;
use Spatie\Permission\Models\Role;

class ServiceProviderController extends Controller
{
    use ApiResponseTrait;

    public function index()
    {

        try {
            $perPage = max(1, min((int) request('per_page', 20), 50));

            $service_providers = ServiceProvider::with('user:id,name,email,phone')
                ->select('id', 'user_id', 'name', 'address', 'logo', 'description', 'created_at')
                ->where('status', 'approved')
                ->orderBy('created_at', 'desc')
                ->paginate($perPage);
            return $this->successResponse($service_providers, 'auth', 'fetched_successfully');
        } catch (\Throwable $e) {

            return $this->errorResponse('fetch_failed', 'auth', 500, [
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function requests()
    {
        try {
            $service_provider_requests = ServiceProvider::with('user:id,name,email,phone', 'attachments')
                ->select('id', 'user_id', 'name', 'address', 'logo', 'description', 'created_at')
                ->where('status', 'pending')
                ->orderBy('created_at', 'desc')
                ->paginate(20);
            return $this->successResponse($service_provider_requests, 'auth', 'fetched_successfully');
        } catch (\Throwable $e) {

            return $this->errorResponse('fetch_failed', 'auth', 500, [
                'error' => $e->getMessage(),
            ]);
        }
    }


    protected function forceLogoutUser(User $user): void
    {
        $tokenIds = $user->tokens()->pluck('id');

        $user->tokens()->whereIn('id', $tokenIds)->update(['revoked' => true]);

        if ($tokenIds->isNotEmpty()) {
            DB::table('oauth_refresh_tokens')
                ->whereIn('access_token_id', $tokenIds)
                ->update(['revoked' => true]);
        }
    }

    public function updateServiceProviderStatus(Request $request, ServiceProvider $service_provider)
    {
        $request->validate([
            'status' => 'required|in:approved,rejected',
        ]);
        try {
            DB::beginTransaction();
            $service_provider->status = $request->status;
            $service_provider->save();
            if ($service_provider->status == 'approved') {
                $role = Role::where('name', 'service_provider')->where('guard_name', 'web')->first();
                if (!$role) {
                    throw new \Exception(__('auth.role_not_found'));
                }
                $service_provider->user->assignRole($role);
                $service_provider->user->syncRoles(['customer', 'service_provider']);
                $this->forceLogoutUser($service_provider->user);
            }

            DB::commit();
            return $this->successResponse($service_provider, 'auth', 'service_provider_status_updated_successfully');
        } catch (Throwable $e) {
            DB::rollBack();

            return $this->errorResponse('updated_failed', 'auth', 500, [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
