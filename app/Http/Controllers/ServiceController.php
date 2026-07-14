<?php

namespace App\Http\Controllers;

use App\Http\Requests\Ads\ServiceRequest;
use App\Models\Category;
use App\Models\Service;
use App\Models\ServiceProvider;
use App\Traits\ApiResponseTrait;
use Illuminate\Support\Facades\Storage;

class ServiceController extends Controller
{
    use ApiResponseTrait;

    protected function serviceRelations(): array
    {
        return [
            'images:id,service_id,image',
            'category:id,name',
            'serviceProvider.user:id,name,email,phone',
            'serviceProvider:id,user_id,name,logo,address,description',
        ];
    }

    protected function baseServiceSelect(): array
    {
        return [
            'id',
            'title',
            'description',
            'price',
            'category_id',
            'provider_id',
            'created_at',
            'duration_minutes',
            'location_type',
            'is_active',
            'max_concurrent_bookings',
            'slot_interval_minutes',
            'cancel_cutoff_hours',
            'edit_cutoff_hours',
            'cancel_late_policy',
            'edit_late_policy',
        ];
    }

    protected function resolveAuthenticatedProvider(): ?ServiceProvider
    {
        $user = auth()->guard()->user();

        return ServiceProvider::where('user_id', $user->id)->first();
    }

    protected function ensureServiceOwnership(Service $service, ServiceProvider $serviceProvider): void
    {
        abort_unless($service->provider_id === $serviceProvider->id, 403);
    }

    protected function syncImages(Service $service, ServiceProvider $serviceProvider, ServiceRequest $request): void
    {
        if (!$request->hasFile('images')) {
            return;
        }

        foreach ($service->images as $image) {
            $path = preg_replace('#^storage/#', '', $image->image ?? '');

            if (!empty($path)) {
                Storage::disk('public')->delete($path);
            }

            $image->delete();
        }

        foreach ($request->file('images') as $image) {
            $service->images()->create([
                'image' => 'storage/' . $image->store('services/' . $serviceProvider->id, 'public'),
            ]);
        }
    }

    public function index()
    {
        $perPage = max(1, min((int) request('per_page', 20), 50));

        $query = Service::with($this->serviceRelations())
            ->select($this->baseServiceSelect());

        if (request()->has('category_id')) {
            $query->where('category_id', request('category_id'));
        }

        $services = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return $this->successResponse($services, 'messages', 'services_retrieved_successfully');
    }

    public function show(Service $service)
    {
        $service->load($this->serviceRelations());

        return $this->successResponse($service, 'messages', 'services_retrieved_successfully');
    }

    public function myServices()
    {
        $perPage = max(1, min((int) request('per_page', 20), 50));
        $serviceProvider = $this->resolveAuthenticatedProvider();

        if (!$serviceProvider) {
            return $this->errorResponse('service_provider_not_found', 'messages', 404);
        }

        $query = Service::where('provider_id', $serviceProvider->id)
            ->with($this->serviceRelations())
            ->select($this->baseServiceSelect());

        if (request()->has('category_id')) {
            $query->where('category_id', request('category_id'));
        }

        $services = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return $this->successResponse($services, 'messages', 'services_retrieved_successfully');
    }

    public function servicesByServiceProvider($id)
    {
        $perPage = max(1, min((int) request('per_page', 20), 50));

        $serviceProvider = ServiceProvider::query()
            ->with(['user:id,name,email,phone'])
            ->select('id', 'user_id', 'name', 'logo', 'address', 'description')
            ->findOrFail($id);

        $services = Service::where('provider_id', $serviceProvider->id)
            ->with($this->serviceRelations())
            ->select($this->baseServiceSelect())
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return $this->successResponse(
            [
                'service_provider' => $serviceProvider,
                'services' => $services,
            ],
            'messages',
            'services_retrieved_successfully'
        );
    }

    public function store(ServiceRequest $request)
    {
        $data = $request->except('images');
        $serviceProvider = $this->resolveAuthenticatedProvider();

        if (!$serviceProvider) {
            return $this->errorResponse('service_provider_not_found', 'messages', 404);
        }

        $data['provider_id'] = $serviceProvider->id;
        $service = Service::create($data);

        $this->syncImages($service, $serviceProvider, $request);

        return $this->successResponse(
            $service->fresh()->load($this->serviceRelations()),
            'services',
            'service_created_successfully'
        );
    }

    public function update(ServiceRequest $request, Service $service)
    {
        $serviceProvider = $this->resolveAuthenticatedProvider();

        if (!$serviceProvider) {
            return $this->errorResponse('service_provider_not_found', 'messages', 404);
        }

        $this->ensureServiceOwnership($service, $serviceProvider);

        $service->update($request->except('images'));
        $this->syncImages($service, $serviceProvider, $request);

        return $this->successResponse(
            $service->fresh()->load($this->serviceRelations()),
            'services',
            'service_updated_successfully'
        );
    }

    public function destroy(Service $service)
    {
        $serviceProvider = $this->resolveAuthenticatedProvider();

        if (!$serviceProvider) {
            return $this->errorResponse('service_provider_not_found', 'messages', 404);
        }

        $this->ensureServiceOwnership($service, $serviceProvider);

        foreach ($service->images as $image) {
            $path = preg_replace('#^storage/#', '', $image->image ?? '');

            if (!empty($path)) {
                Storage::disk('public')->delete($path);
            }

            $image->delete();
        }

        $service->delete();

        return $this->successResponse([], 'services', 'service_deleted_successfully');
    }

    public function servicesByCategory($categoryId)
    {
        $categoryIds = Category::where('parent_id', $categoryId)
            ->pluck('id')->toArray();
        $categoryIds[] = $categoryId;

        $services = Service::whereIn('category_id', $categoryIds)
            ->with($this->serviceRelations())
            ->select($this->baseServiceSelect())
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return $this->successResponse($services, 'messages', 'services_retrieved_successfully');
    }
}
