<?php

use App\Http\Controllers\Admin\Data\ImportController;
use App\Http\Controllers\Admin\Data\PopulationController;
use App\Http\Controllers\Admin\MapMarkersController;
use App\Http\Controllers\Admin\ProvinceAgricultureController;
use App\Http\Controllers\Admin\ProvinceResourcesController;
use App\Http\Controllers\Admin\SearchMapController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\LocationRoadsController;
use App\Http\Controllers\PlanningChatController;
use App\Http\Controllers\UserDashboardController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('welcome');
})->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', UserDashboardController::class)->name('user.dashboard.index');

    Route::get('location', [LocationController::class, 'show'])->name('location.show');
    Route::get('location/assess', [LocationController::class, 'assess'])->name('location.assess');
    Route::get('location/assess/data', [LocationController::class, 'assessmentData'])->middleware('throttle:30,1')->name('location.assess.data');
    Route::post('location/assess/ai', [LocationController::class, 'assessmentAi'])->middleware('throttle:6,1')->name('location.assess.ai');

    Route::prefix('planning-chat')->name('planning-chat.')->group(function () {
        Route::get('locations', [PlanningChatController::class, 'locations'])->middleware('throttle:60,1')->name('locations');
        Route::get('history', [PlanningChatController::class, 'history'])->middleware('throttle:60,1')->name('history');
        Route::get('saved-plans', [PlanningChatController::class, 'savedPlans'])->middleware('throttle:60,1')->name('saved-plans');
        Route::post('conversations/{conversation}/resume', [PlanningChatController::class, 'resume'])->whereNumber('conversation')->middleware('throttle:20,1')->name('resume');
        Route::get('plans/{plan}', [PlanningChatController::class, 'plan'])->whereNumber('plan')->middleware('throttle:60,1')->name('plan');
        Route::post('plans', [PlanningChatController::class, 'savePlan'])->middleware('throttle:20,1')->name('save-plan');
        Route::get('/', [PlanningChatController::class, 'show'])->middleware('throttle:60,1')->name('show');
        Route::post('/', [PlanningChatController::class, 'store'])->middleware('throttle:10,1')->name('store');
        Route::delete('/', [PlanningChatController::class, 'destroy'])->middleware('throttle:10,1')->name('destroy');
    });

    Route::prefix('intelligence-map')->name('user.intelligence-map.')->group(function () {
        Route::get('/', [SearchMapController::class, 'userMap'])->name('index');
        Route::middleware('throttle:180,1')->group(function () {
            Route::get('markers', MapMarkersController::class)->name('markers');
            Route::get('provinces/{province}/agriculture', ProvinceAgricultureController::class)->whereNumber('province')->name('agriculture');
            Route::get('provinces/{province}/resources', ProvinceResourcesController::class)->whereNumber('province')->name('resources');
            Route::get('geography', [SearchMapController::class, 'geography'])->name('geography');
            Route::get('search', [SearchMapController::class, 'search'])->name('search');
            Route::get('locations/{level}/{id}', [SearchMapController::class, 'selection'])->whereNumber('id')->name('selection');
            Route::get('locations/{level}/{id}/roads', LocationRoadsController::class)->whereNumber('id')->name('roads');
        });
    });
});

// Admin area. EnsureUserHasRole bounces anyone without an accepted role to
// their own dashboard via User::dashboardRoute().
Route::middleware(['auth'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        // The admin dashboard itself is administrators only.
        Route::middleware('role:admin')->group(function () {
            Route::get('assessment', [SearchMapController::class, 'assessment'])->name('assessment.index');
            Route::get('assessment/start', [SearchMapController::class, 'startAssessment'])->name('assessment.start');

            Route::prefix('search-map')->name('search-map.')->group(function () {
                Route::get('/', [SearchMapController::class, 'show'])->name('index');
                Route::middleware('throttle:180,1')->group(function () {
                    Route::get('markers', MapMarkersController::class)->name('markers');
                    Route::get('provinces/{province}/agriculture', ProvinceAgricultureController::class)->whereNumber('province')->name('agriculture');
                    Route::get('provinces/{province}/agriculture/records', [ProvinceAgricultureController::class, 'records'])->whereNumber('province')->name('agriculture.records');
                    Route::get('provinces/{province}/resources', ProvinceResourcesController::class)->whereNumber('province')->name('resources');
                    Route::get('geography', [SearchMapController::class, 'geography'])->name('geography');
                    Route::get('search', [SearchMapController::class, 'search'])->name('search');
                    Route::get('locations/{level}/{id}', [SearchMapController::class, 'selection'])->whereNumber('id')->name('selection');
                    Route::get('locations/{level}/{id}/roads', LocationRoadsController::class)->whereNumber('id')->name('roads');
                });
            });

            Route::get('dashboard', function () {
                return Inertia::render('admin/dashboard');
            })->name('dashboard.index');
        });

        // Reference data. Technicians maintain datasets; only admins reach the
        // sections below this group.
        // Route::middleware('role:admin,technician')->group(function () {
        //     Route::get('data/population', [PopulationController::class, 'index'])
        //         ->name('data.population.index');
        //     Route::patch('data/population/{populationFigure}', [PopulationController::class, 'update'])
        //         ->name('data.population.update');
        //     Route::post('data/vintages/{dataVintage}/current', [PopulationController::class, 'makeCurrent'])
        //         ->name('data.vintages.current');

        //     Route::get('data/import', [ImportController::class, 'index'])
        //         ->name('data.import.index');
        //     Route::post('data/import/preview', [ImportController::class, 'preview'])
        //         ->name('data.import.preview');
        //     Route::post('data/import/commit', [ImportController::class, 'commit'])
        //         ->name('data.import.commit');
        // });
    });

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
