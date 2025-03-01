<?php

namespace App\Transformers\Driver;

use Carbon\Carbon;
use App\Models\User;
use App\Models\Admin\Driver;
use App\Base\Constants\Auth\Role;
use App\Transformers\Transformer;
use App\Models\Request\RequestBill;
use App\Models\Request\RequestMeta;
use App\Models\Admin\DriverDocument;
use App\Models\Admin\DriverNeededDocument;
use App\Transformers\Access\RoleTransformer;
use App\Transformers\Requests\TripRequestTransformer;

class DriverTransformer extends Transformer
{
    /**
     * Resources that can be included if requested.
     *
     * @var array
     */
         public function __construct()
    {
    	$this->availableIncludes = [
         'onTripRequest','metaRequest'     ];
        
        
    }


 

    /**
     * A Fractal transformer.
     *
     * @return array
     */
    public function transform(Driver $user)
    {
        $params = [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'mobile' => $user->user->countryDetail->dial_code.$user->mobile,
            'profile_picture' => $user->profile_picture,
            'active' => (bool)$user->active,
            'approve' => (bool)$user->approve,
            'available' => (bool)$user->available,
            'uploaded_document'=>false,
            'declined_reason'=>$user->reason,
            'service_location_id'=>$user->service_location_id,
            'vehicle_type_id'=> $user->vehicle_type,
            'vehicle_type_name'=>$user->vehicle_type_name,
            'car_make'=>$user->car_make,
            'car_model'=>$user->car_model,
            'car_make_name'=>$user->car_make_name,
            'car_model_name'=>$user->car_model_name,
            'car_color'=>$user->car_color,
            'driver_lat'=>$user->driver_lat,
            'driver_lng'=>$user->driver_lng,
            'car_number'=>$user->car_number,
            'rating'=>round($user->rating, 2),
            'no_of_ratings' => $user->no_of_ratings,
            'timezone'=>$user->timezone,
            'refferal_code'=>$user->user->refferal_code,
            //'map_key'=>get_settings('google_map_key'),
            'company_key'=>$user->user->company_key,
            'level_ar'=>$user->user->level_ar,
             'level_en'=>$user->user->level_en,
            
            'show_instant_ride'=>true,
            
                         'points_balance' =>$user->points_balance,

            'currency_symbol' => $user->user->countryDetail?$user->user->countryDetail->currency_symbol:'₹'
        ];

        $current_date = Carbon::now();

        $total_earnings = RequestBill::whereHas('requestDetail', function ($query) use ($user,$current_date) {
            $query->where('driver_id', $user->id)->where('is_completed', 1)->whereDate('trip_start_time', $current_date);
        })->sum('driver_commision');

        $timezone = $user->user->timezone?:env('SYSTEM_DEFAULT_TIMEZONE');

        $updated_current_date =  $current_date->setTimezone($timezone);

        $params['total_earnings'] = $total_earnings;
        $params['current_date'] = $updated_current_date->toDateString();

        foreach (DriverNeededDocument::active()->get() as $key => $needed_document) {
            if (DriverDocument::where('driver_id', $user->id)->where('document_id', $needed_document->id)->exists()) {
                $params['uploaded_document'] = true;
            } else {
                $params['uploaded_document'] = false;
            }
        }

        return $params;
    }

    /**
     * Include the request of the driver.
     *
     * @param User $user
     * @return \League\Fractal\Resource\Collection|\League\Fractal\Resource\NullResource
     */
    public function includeOnTripRequest(Driver $user)
    {
        // dd($user);
        $request = $user->requestDetail()->where('is_cancelled', false)->where('driver_rated', false)->first();

        return $request
        ? $this->item($request, new TripRequestTransformer)
        : $this->null();
    }

    /**
     * Include the meta request of the driver.
     *
     * @param User $user
     * @return \League\Fractal\Resource\Collection|\League\Fractal\Resource\NullResource
     */
    public function includeMetaRequest(Driver $user)
    {
        $request_meta = RequestMeta::where('driver_id', $user->id)->where('active', true)->first();
        if ($request_meta) {
            $request = $request_meta->request;
            return $request
        ? $this->item($request, new TripRequestTransformer)
        : $this->null();
        }
        return $this->null();
    }
}
