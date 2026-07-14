<?php

use App\Http\Controllers\AdController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\ListingAdController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\SellerController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\ServiceProviderController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Broadcast;
use App\Http\Controllers\Api\Auth\RegisterController;
use App\Http\Controllers\Api\Auth\LoginController;
use App\Http\Controllers\Api\Auth\PasswordUpdateController;
use App\Http\Controllers\Api\Auth\PasswordResetController;
use App\Http\Controllers\Api\Auth\UpgradeAccountController;
use App\Http\Controllers\Api\Admin\UpgradeRequestController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\OrderCheckoutController;
use App\Http\Controllers\ServiceAvailabilityController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\ServiceBookingController;
use App\Http\Controllers\ServiceProviderAvailabilityController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ConnectAccountController;
use App\Http\Controllers\Api\Admin\PlatformSettingsController;
use App\Http\Controllers\Api\Admin\PayoutController;
use App\Http\Controllers\ProfileController;

Route::middleware(['set-language', 'throttle:api'])->group(function () {

    Route::post('/stripe/webhook', [StripeWebhookController::class, 'handle']);
    Route::get('/services/{service}/availability', [ServiceAvailabilityController::class, 'day']);


    Route::middleware(['throttle:auth'])->group(function () {

        Route::post('login', [LoginController::class, 'login']);
        Route::post('register', [RegisterController::class, 'register']);
        Route::post('password/forgot', [PasswordResetController::class, 'sendResetOtp']);
        Route::post('password/verify-otp', [PasswordResetController::class, 'verifyOtp']);
        Route::post('password/reset', [PasswordResetController::class, 'resetPassword']);
    });


    Route::get('sellers', [SellerController::class, 'index']);
    Route::get('service_providers', [ServiceProviderController::class, 'index']);
    Route::get('categories', [CategoryController::class, 'index']);
    Route::get('products', [ProductController::class, 'index']);
    Route::get('products/{product}', [ProductController::class, 'show']);
    Route::get('services', [ServiceController::class, 'index']);
    Route::get('services/{service}', [ServiceController::class, 'show']);
    Route::get('ads/timed', [AdController::class, 'timedAds']);

    Route::get('service_provider/{id}/services', [ServiceController::class, 'servicesByServiceProvider']);
    Route::get('seller/{id}/products', [ProductController::class, 'productsBySeller']);

    // Route::post('seller/register', [RegisterController::class, 'sellerRegister']);
    // Route::post('service_provider/register', [RegisterController::class, 'service_providerRegister']);
    // Route::post('guest/register', [RegisterController::class, 'guestRegister']);
    Route::get('home', [HomeController::class, 'index']);

    Route::get('ads', [AdController::class, 'index']);
    Route::get('ads/gold', [AdController::class, 'goldIndex']);
    Route::get('ads/silver', [AdController::class, 'silverIndex']);
    Route::get('ads/normal', [AdController::class, 'normalIndex']);

    Route::middleware(['auth:sanctum'])->group(function () {


        Route::post('logout', [LoginController::class, 'logout']);
        Route::post('logout-all', [LoginController::class, 'logoutAll']);

        Route::post('update-password', [PasswordUpdateController::class, 'update']);
        Route::get('me', [ProfileController::class, 'show']);
        Route::get('me/orders', [OrderController::class, 'myOrders']);
        Route::get('me/sales', [OrderController::class, 'mySales']);
        Route::get('me/provider-bookings', [ServiceBookingController::class, 'providerBookings']);

        Route::prefix('connect')->group(function () {
            Route::post('/onboard', [ConnectAccountController::class, 'start']);
            Route::get('/status', [ConnectAccountController::class, 'status']);
            Route::get('/summary', [ConnectAccountController::class, 'summary']);
        });

        Route::get('/conversations/unread-count', [ConversationController::class, 'unreadCount']);

        Route::get('/conversations', [ConversationController::class, 'index']);

        // Start or get a 1-1 conversation
        Route::post('/conversations/direct', [ConversationController::class, 'startDirect']);

        // Get messages for a conversation (paginated)
        Route::get('/conversations/{conversation}/messages', [MessageController::class, 'index']);

        // Send message
        Route::post('/conversations/{conversation}/messages', [MessageController::class, 'store']);

        // Acknowledge delivery (recipient device confirms it received via WS)
        Route::post('/messages/{message}/delivered', [MessageController::class, 'ackDelivered']);

        // Mark read (recipient viewed)
        Route::post('/messages/{message}/read', [MessageController::class, 'markRead']);

        // (Optional) mark entire conversation read up to latest
        Route::post('/conversations/{conversation}/read', [MessageController::class, 'markConversationRead']);






        Route::post('category', [CategoryController::class, 'store']);
        Route::get('category/{category}/products', [ProductController::class, 'productsByCategory']);


        Route::post('ads/{ad}/images', [AdController::class, 'addImages']);
        Route::post('ads', [AdController::class, 'store']);
        Route::post('/listing-ads', [ListingAdController::class, 'store']);
        Route::get('my-ads', [AdController::class, 'myAds']);


        Route::get('/customers', [CustomerController::class, 'index']);


        Route::middleware(['role:admin|seller|service_provider'])->group(function () {
            Route::post('categories', [CategoryController::class, 'store']);
            Route::put('categories/{category}', [CategoryController::class, 'update']);
            Route::delete('categories/{category}', [CategoryController::class, 'destroy']);
            Route::post('product', [ProductController::class, 'store']);
            Route::post('service', [ServiceController::class, 'store']);
            Route::put('service/{service}', [ServiceController::class, 'update']);
            Route::delete('service/{service}', [ServiceController::class, 'destroy']);
            Route::get('my-products', [ProductController::class, 'myProducts']);
            Route::get('my-services', [ServiceController::class, 'myServices']);
        });





        Route::prefix('orders')->group(function () {
            Route::post('/', [OrderController::class, 'store']);
            Route::get('/my-sales', [OrderController::class, 'mySales']);
            Route::get('/my-orders', [OrderController::class, 'myOrders']);
            Route::get('/{order}', [OrderController::class, 'show']);
            Route::post('/{order}/items', [OrderController::class, 'addItem']);
            Route::post('/{order}/checkout', [OrderCheckoutController::class, 'createPaymentIntent']);
            Route::post('/{order}/checkout-session', [OrderCheckoutController::class, 'createCheckoutSession']);
            Route::post('/{order}/checkout-session/reconcile', [OrderCheckoutController::class, 'reconcileCheckoutSession']);
        });

        // customer creates booking for a service (order flow only)
        // Route::post('/services/{service}/bookings', [ServiceBookingController::class, 'store']);

        // customer views their bookings
        Route::get('/me/bookings', [ServiceBookingController::class, 'myBookings']);

        // provider views bookings assigned to them
        Route::get('/provider/bookings', [ServiceBookingController::class, 'providerBookings']);

        // provider confirms booking
        Route::patch('/bookings/{booking}/confirm', [ServiceBookingController::class, 'confirm']);

        // customer or provider cancels booking
        Route::patch('/bookings/{booking}/cancel', [ServiceBookingController::class, 'cancel']);

        // customer or provider reschedules booking
        Route::patch('/bookings/{booking}/reschedule', [ServiceBookingController::class, 'reschedule']);

        // provider marks completed
        Route::patch('/bookings/{booking}/complete', [ServiceBookingController::class, 'complete']);



        Route::middleware(['role:admin'])->group(function () {
            Route::prefix('admin')->group(function () {
                Route::post('seller/{seller}/status', [SellerController::class, 'updateSellerStatus']);
                Route::post('service_provider/{service_provider}/status', [ServiceProviderController::class, 'updateServiceProviderStatus']);
                Route::post('ad/{ad}/status', [AdController::class, 'updateStatus']);

                Route::prefix('upgrade-requests')->group(function () {
                    Route::get('/', [UpgradeRequestController::class, 'index']);
                    Route::post('/seller/{seller}/approve', [UpgradeRequestController::class, 'approveSeller']);
                    Route::post('/seller/{seller}/reject', [UpgradeRequestController::class, 'rejectSeller']);
                    Route::post('/service-provider/{service_provider}/approve', [UpgradeRequestController::class, 'approveServiceProvider']);
                    Route::post('/service-provider/{service_provider}/reject', [UpgradeRequestController::class, 'rejectServiceProvider']);
                });

                Route::get('/settings', [PlatformSettingsController::class, 'show']);
                Route::put('/settings', [PlatformSettingsController::class, 'update']);

                Route::get('/payouts', [PayoutController::class, 'index']);
                Route::post('/payouts/{user}/pay', [PayoutController::class, 'payUser']);
                Route::post('/payouts/pay-all', [PayoutController::class, 'payAll']);
            });

            Route::prefix('seller')->group(function () {
                Route::get('/requests', [SellerController::class, 'requests']);
            });
            Route::prefix('service_provider')->group(function () {
                Route::get('/requests', [ServiceProviderController::class, 'requests']);
            });


            Route::prefix('ads')->group(function () {
                Route::get('pending', [AdController::class, 'getPendingAds']);
                Route::get('general', [AdController::class, 'getGeneralAds']);
                Route::prefix('/requests')->group(function () {
                    Route::get('timed', [AdController::class, 'timedAdRequests']);
                    Route::get('banner', [AdController::class, 'bannerdAdRequests']);
                });
            });
        });

        Route::middleware(['role:admin|service_provider'])->group(function () {
            Route::prefix('service_provider')->group(function () {
                Route::get('/availability', [ServiceProviderAvailabilityController::class, 'show']);
                Route::put('/working-hours', [ServiceProviderAvailabilityController::class, 'updateWorkingHours']);
                Route::post('/time-off', [ServiceProviderAvailabilityController::class, 'addTimeOff']);
                Route::delete('/time-off/{timeOff}', [ServiceProviderAvailabilityController::class, 'deleteTimeOff']);
            });
        });


        // Seller routes
        Route::middleware(['role:seller'])->prefix('seller')->group(function () {});

        // Customer routes
        Route::middleware(['role:customer'])->prefix('customer')->group(function () {
            Route::post('upgrade-to-seller', [UpgradeAccountController::class, 'upgradeToSeller']);
            Route::post('upgrade-to-service_provider', [UpgradeAccountController::class, 'upgradeToServiceProvider']);
        });
    });
});
