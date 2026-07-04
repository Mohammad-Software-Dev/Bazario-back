<?php

namespace App\Http\Controllers;

use App\Models\Seller;
use App\Models\User;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;
use Spatie\Permission\Models\Role;

class SellerController extends Controller
{
    use ApiResponseTrait;


    public function requests()
    {
        try {
            $sellers = Seller::with('user:id,name,email,phone', 'attachments')
                ->select('id', 'user_id', 'store_owner_name', 'store_name', 'address', 'logo', 'description', 'created_at')
                ->where('status', 'pending')
                ->orderBy('created_at', 'desc')
                ->paginate(20);
            return $this->successResponse($sellers, 'auth', 'fetched_successfully');
        } catch (\Throwable $e) {

            return $this->errorResponse('fetch_failed', 'auth', 500, [
                'error' => $e->getMessage(),
            ]);
        }
    }
    public function index()
    {
        try {
            $perPage = max(1, min((int) request('per_page', 20), 50));

            $sellers = Seller::with('user:id,name,email,phone')
                ->select('id', 'user_id', 'store_owner_name', 'store_name', 'address', 'logo', 'description', 'created_at')
                ->where('status', 'approved')
                ->orderBy('created_at', 'desc')
                ->paginate($perPage);


            return $this->successResponse($sellers, 'auth', 'fetched_successfully');
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


    public function updateSellerStatus(Request $request, Seller $seller)
    {
        $request->validate([
            'status' => 'required|in:approved,rejected',
        ]);
        try {
            DB::beginTransaction();
            $seller->status = $request->status;
            $seller->save();
            if ($seller->status == 'approved') {
                $role = Role::where('name', 'seller')->where('guard_name', 'web')->first();
                if (!$role) {
                    throw new \Exception(__('auth.role_not_found'));
                }
                $seller->user->assignRole($role);
                $seller->user->syncRoles(['customer', 'seller']);
                $this->forceLogoutUser($seller->user);
            }

            DB::commit();
            return $this->successResponse($seller, 'auth', 'seller_status_updated_successfully');
        } catch (Throwable $e) {
            DB::rollBack();

            return $this->errorResponse('updated_failed', 'auth', 500, [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
