<?php

namespace App\Http\Controllers;

use App\Http\Requests\Ads\ServiceRequest;
use App\Models\Category;
use App\Models\Service;
use App\Models\ServiceProvider;
use App\Traits\ApiResponseTrait;


class ServiceController extends Controller
{
    use ApiResponseTrait;
    public function index()
    {
        $perPage = max(1, min((int) request('per_page', 20), 50));

        $query = Service::with([
            'images:id,service_id,image',
            'category:id,name',
            'serviceProvider.user:id,name,email,phone',
            'serviceProvider:id,user_id,name,logo,address,description'
        ])
            ->select('id', 'title', 'description',  'price',  'category_id', 'provider_id', 'created_at');

        if (request()->has('category_id')) {
            $query->where('category_id', request('category_id'));
        }

        $services = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return $this->successResponse($services, 'messages', 'services_retrieved_successfully');
    }

    public function myServices()
    {
        $user = auth()->guard()->user();
        $service_provider = ServiceProvider::where('user_id', $user->id)->first();

        $query = Service::where('provider_id', $service_provider->id)
            ->with([
                'images:id,service_id,image',
                'category:id,name',
                'serviceProvider.user:id,name,email,phone',
                'serviceProvider:id,user_id,name,logo,address,description'
            ])
            ->select('id', 'title', 'description',  'price',  'category_id', 'provider_id', 'created_at');

        if (request()->has('category_id')) {
            $query->where('category_id', request('category_id'));
        }

        $services = $query->orderBy('created_at', 'desc')->paginate(20);

        return $this->successResponse($services, 'messages', 'services_retrieved_successfully');
    }

    public function servicesByServiceProvider($id)
    {
        $services = Service::where('provider_id', $id)->with([
            'images:id,service_id,image',
            'category:id,name',
            'serviceProvider.user:id,name,email,phone',
            'serviceProvider:id,user_id,name,logo,address,description'
        ])
            ->select('id', 'title', 'description',  'price',  'category_id', 'provider_id', 'created_at')
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return $this->successResponse($services, 'messages', 'services_retrieved_successfully');
    }

    public function store(ServiceRequest $request)
    {
        $data = $request->except('images');
        $user = auth()->guard()->user();
        $service_provider = ServiceProvider::where('user_id', $user->id)->first();
        if (!$service_provider) {
            return $this->errorResponse('service_provider_not_found', 'messages', 404);
        }

        $data['provider_id'] = $service_provider->id;
        $service = Service::create($data);

        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $index => $image) {
                $service->images()->create([
                    'image' => 'storage/' . $image->store('services/' . $service_provider->id, 'public'),
                ]);
            }
        }

        return $this->successResponse($service->load('images'), 'services', 'service_created_successfully');
    }


    public function servicesByCategory($categoryId)
    {
        $categoryIds = Category::where('parent_id', $categoryId)
            ->pluck('id')->toArray();
        $categoryIds[] = $categoryId;

        $services = Service::whereIn('category_id', $categoryIds)
            ->with([
                'images:id,service_id,image',
                'category:id,name',
                'serviceProvider.user:id,name,email,phone',
                'serviceProvider:id,user_id,name,logo,address,description'
            ])
            ->select('id', 'title', 'description', 'price', 'category_id', 'provider_id', 'created_at')
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return $this->successResponse($services, 'messages', 'services_retrieved_successfully');
    }
}
