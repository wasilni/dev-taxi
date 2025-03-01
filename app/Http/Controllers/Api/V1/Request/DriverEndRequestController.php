<?php

namespace App\Http\Controllers\Api\V1\Request;

use App\Jobs\NotifyViaMqtt;
use App\Models\Admin\Promo;
use App\Jobs\NotifyViaSocket;
use Kreait\Firebase\Database;
use Illuminate\Support\Carbon;
use App\Models\Admin\PromoUser;
use App\Base\Constants\Masters\UnitType;
use App\Base\Constants\Masters\PushEnums;
use App\Base\Constants\Masters\PaymentType;
use App\Base\Constants\Masters\WalletRemarks;
use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Requests\Request\DriverEndRequest;
use App\Jobs\Notifications\AndroidPushNotification;
use App\Transformers\Requests\TripRequestTransformer;
use App\Models\Admin\ZoneTypePackagePrice;
use Illuminate\Support\Facades\Log;
use App\Models\Request\RequestCancellationFee;
use App\Models\Setting;
use App\Models\User;
use App\Models\Request\Request;
use App\Models\Level;
use App\Notifications\DriverfinishedtripNotification;



/**
 * @group Driver-trips-apis
 *
 * APIs for Driver-trips apis
 */
class DriverEndRequestController extends BaseController
{
    public function __construct(Database $database)
    {
        $this->database = $database;
    }
    /**
    * Driver End Request
    * @bodyParam request_id uuid required id request
    * @bodyParam distance double required distance of request
    * @bodyParam before_trip_start_waiting_time double required before arrival waiting time of request
    * @bodyParam after_trip_start_waiting_time double required after arrival waiting time of request
    * @bodyParam drop_lat double required drop lattitude of request
    * @bodyParam drop_lng double required drop longitude of request
    * @bodyParam drop_address double required drop drop Address of request
    * @responseFile responses/requests/request_bill.json
    *
    */
    public function endRequest(DriverEndRequest $request)
    {
        // Get Request Detail
        $driver = auth()->user()->driver;

        $request_detail = $driver->requestDetail()->where('id', $request->request_id)->first();

        if (!$request_detail) {
            $this->throwAuthorizationException();
        }
        // Validate Trip request data
        if ($request_detail->is_completed) {
            // @TODO send success response with bill object
            // $this->throwCustomException('request completed already');
            $request_result = fractal($request_detail, new TripRequestTransformer)->parseIncludes('requestBill');
            return $this->respondSuccess($request_result, 'request_ended');
        }
        if ($request_detail->is_cancelled) {
            $this->throwCustomException('request cancelled');
        }

        $firebase_request_detail = $this->database->getReference('requests/'.$request_detail->id)->getValue();

        $request_place_params = ['drop_lat'=>$request->drop_lat,'drop_lng'=>$request->drop_lng,'drop_address'=>$request->drop_address];

        if ($firebase_request_detail) {
            if(array_key_exists('lat_lng_array',$firebase_request_detail)){
                $locations = $firebase_request_detail['lat_lng_array'];
                $request_place_params['request_path'] = $locations;
            }
        }
        // Remove firebase data
        // $this->database->getReference('requests/'.$request_detail->id)->remove();

        // Update Droped place details
        $request_detail->requestPlace->update($request_place_params);
        // Update Driver state as Available
        $request_detail->driverDetail->update(['available'=>true]);
        // Get currency code of Request
        $service_location = $request_detail->zoneType->zone->serviceLocation;

        $currency_code = $service_location->currency_code;
        $requested_currency_symbol = $service_location->currency_symbol;

        if (!$request_detail->is_later) {
            $ride_type = 1;
        } else {
            $ride_type = 2;
        }
        $zone_type = $request_detail->zoneType;

        $zone_type_price = $zone_type->zoneTypePrice()->where('price_type', $ride_type)->first();


        $distance_matrix = get_distance_matrix($request_detail->pick_lat, $request_detail->pick_lng, $request_detail->drop_lat, $request_detail->drop_lng, true);

        $distance = (double)$request->distance;
        $duration = $this->calculateDurationOfTrip($request_detail->trip_start_time);

        // if ($distance_matrix->status =="OK" && $distance_matrix->rows[0]->elements[0]->status != "ZERO_RESULTS") {
        //     $distance_in_meters = get_distance_value_from_distance_matrix($distance_matrix);
        //     $distance = $distance_in_meters / 1000;

        //     if ($distance < $request->distance) {
        //         $distance = (double)$request->distance;
        //     }

        //     //If we need we can use these lines
        //     // $duration = get_duration_text_from_distance_matrix($distance_matrix);
        //     // $duration_in_mins = explode(' ', $duration);
        //     // $duration = (double)$duration_in_mins[0];
        // }
        if ($request_detail->unit==UnitType::MILES) {
            $distance = kilometer_to_miles($distance);
        }

        // Update Request status as completed
        $request_detail->update([
            'is_completed'=>true,
            'completed_at'=>date('Y-m-d H:i:s'),
            'is_paid'=>1,
            'total_distance'=>$distance,
            'total_time'=>$duration,
            ]);

            $totalTrips = Request::where('driver_id',$driver->id)->companyKey()->whereIsCompleted(true)->count();

            $levfiesrt=Level::where('id','1')->first();
            $levsecond=Level::where('id','2')->first();
            $levthird=Level::where('id','3')->first();
            $levsefourth=Level::where('id','4')->first();
            $driver= User::where('id',auth()->user()->id)->first();

  if($levfiesrt->no_trip >= $totalTrips){
            $driver->level_ar =$levfiesrt->name_ar;
            $driver->level_en =$levfiesrt->name_en;
            $driver->update();

            }
elseif($levsecond->no_trip >= $totalTrips && $totalTrips < $levthird->no_trip){



                $driver->level_ar =$levsecond->name_ar;
                $driver->level_en =$levsecond->name_en;
                $driver->update();

        }


        elseif($levthird->no_trip >= $totalTrips && $totalTrips < $levsefourth->no_trip ){



            $driver->level_ar =$levthird->name_ar;
            $driver->level_en =$levthird->name_en;
            $driver->update();

    }

    else{

        $driver->level_ar =$levsefourth->name_ar;
        $driver->level_en =$levsefourth->name_en;
        $driver->update();
    }


            //here al--------------------------------------------------------
            $Setting= Setting::where('name','trip_point')->first();

            $requestcompleted=Request::find($request->request_id);
            $client= User::find($requestcompleted->user_id);
            $client->points_balance=$client->points_balance+$Setting->value;
            $client->update();

             $Setting_Bronze_level_points= Level::where('name_en','pronze')->first();
             $Setting_silver_Points= Level::where('name_en','silver')->first();

            $Setting_golden_points= Level::where('name_en','golden')->first();

            $Setting_diamonds_points= Level::where('name_en','diamonds')->first();


            $driverpoint=auth()->user();
            if($driverpoint->level_en=='pronze'){


                $driverpoint->points_balance=$driverpoint->points_balance+$Setting_Bronze_level_points->no_point;
            }
            if($driverpoint->level_en=='silver'){
                   $driverpoint->points_balance=$driverpoint->points_balance+$Setting_silver_Points->no_point;

            }
             if($driverpoint->level_en=='golden'){
                   $driverpoint->points_balance=$driverpoint->points_balance+$Setting_golden_points->no_point;

            }
                if($driverpoint->level_en=='diamonds'){
                   $driverpoint->points_balance=$driverpoint->points_balance+$Setting_diamonds_points->no_point;

            }

            $driverpoint->update();



            //here al--------------------------------------------------------

        $before_trip_start_waiting_time = $request->input('before_trip_start_waiting_time');
        $after_trip_start_waiting_time = $request->input('after_trip_start_waiting_time');

        $subtract_with_free_waiting_before_trip_start = ($before_trip_start_waiting_time - $zone_type_price->free_waiting_time_in_mins_before_trip_start);

        $subtract_with_free_waiting_after_trip_start = ($after_trip_start_waiting_time - $zone_type_price->free_waiting_time_in_mins_after_trip_start);

        $waiting_time = ($subtract_with_free_waiting_before_trip_start+$subtract_with_free_waiting_after_trip_start);

        if($waiting_time<0){
            $waiting_time = 0;
        }

        // Calculated Fares
        $promo_detail =null;

        if ($request_detail->promo_id) {
            $promo_detail = $this->validateAndGetPromoDetail($request_detail->promo_id);
        }

        $calculated_bill =  $this->calculateRideFares($zone_type_price, $distance, $duration, $waiting_time, $promo_detail,$request_detail);

        $calculated_bill['before_trip_start_waiting_time'] = $before_trip_start_waiting_time;
        $calculated_bill['after_trip_start_waiting_time'] = $after_trip_start_waiting_time;
        $calculated_bill['calculated_waiting_time'] = $waiting_time;
        $calculated_bill['waiting_charge_per_min'] = $zone_type_price->waiting_charge;

        if($request_detail->is_rental && $request_detail->rental_package_id){

            $chosen_package_price = ZoneTypePackagePrice::where('zone_type_id',$request_detail->zone_type_id)->where('package_type_id',$request_detail->rental_package_id)->first();

            $previous_range = 0;
            $exceeding_range = 0;
            $package= null;

        $zone_type_package_prices = $zone_type->zoneTypePackage()->orderBy('free_min','asc')->get();


        foreach ($zone_type_package_prices as $key => $zone_type_package_price) {

            if($zone_type_package_price->free_min == $duration){
                $package = $zone_type_package_price;

                break;
            }
            elseif($zone_type_package_price->free_min < $duration){
                $previous_range = $zone_type_package_price->free_min;
                $previous_zone_type = $zone_type_package_price;
            }
            else{
                $exceeding_range = $zone_type_package_price->free_min;
                $exceeding_zone_type = $zone_type_package_price;
            }

            if($exceeding_range != 0 && $package == null){
                $package = ($previous_range == 0) ? $exceeding_zone_type : $previous_zone_type;


                break;

            } else {
                $package = $previous_zone_type;


            }
        }

        if($package){

            $zone_type_price = $package;
        }else{

            $zone_type_price = $chosen_package_price;
        }

        $request_detail->rental_package_id = $zone_type_price->package_type_id;
        $request_detail->save();

          $calculated_bill =  $this->calculateRentalRideFares($zone_type_price, $distance, $duration, $waiting_time, $promo_detail,$request_detail);

          // Log::info($calculated_bill);

        }


        $calculated_bill['requested_currency_code'] = $currency_code;
        $calculated_bill['requested_currency_symbol'] = $requested_currency_symbol;
        // @TODO need to take admin commision from driver wallet
        if ($request_detail->payment_opt==PaymentType::CASH) {
            // Deduct the admin commission + tax from driver walllet
            $admin_commision_with_tax = $calculated_bill['admin_commision_with_tax'];
            $driver_wallet = $request_detail->driverDetail->driverWallet;
            $driver_wallet->amount_spent += $admin_commision_with_tax;
            $driver_wallet->amount_balance -= $admin_commision_with_tax;
            $driver_wallet->save();

            $driver_wallet_history = $request_detail->driverDetail->driverWalletHistory()->create([
                'amount'=>$admin_commision_with_tax,
                'transaction_id'=>str_random(6),
                'remarks'=>WalletRemarks::ADMIN_COMMISSION_FOR_REQUEST,
                'is_credit'=>false
            ]);
        } elseif ($request_detail->payment_opt==PaymentType::CARD) {
            // @TODO in future
        } else { //PaymentType::WALLET
            // To Detect Amount From User's Wallet
            // Need to check if the user has enough amount to spent for his trip
            $chargable_amount = $calculated_bill['total_amount'];
            $user_wallet = $request_detail->userDetail->userWallet;

            if ($chargable_amount<=$user_wallet->amount_balance) {
                $user_wallet->amount_balance -= $chargable_amount;
                $user_wallet->amount_spent += $chargable_amount;
                $user_wallet->save();

                $user_wallet_history = $request_detail->userDetail->userWalletHistory()->create([
                'amount'=>$chargable_amount,
                'transaction_id'=>$request_detail->id,
                'request_id'=>$request_detail->id,
                'remarks'=>WalletRemarks::SPENT_FOR_TRIP_REQUEST,
                'is_credit'=>false]);

                // @TESTED to add driver commision if the payment type is wallet
                $driver_commision = $calculated_bill['driver_commision'];
                $driver_wallet = $request_detail->driverDetail->driverWallet;
                $driver_wallet->amount_added += $driver_commision;
                $driver_wallet->amount_balance += $driver_commision;
                $driver_wallet->save();

                $driver_wallet_history = $request_detail->driverDetail->driverWalletHistory()->create([
                'amount'=>$driver_commision,
                'transaction_id'=>$request_detail->id,
                'remarks'=>WalletRemarks::TRIP_COMMISSION_FOR_DRIVER,
                'is_credit'=>true
            ]);
            } else {
                $request_detail->payment_opt = PaymentType::CASH;
                $request_detail->save();
                $admin_commision_with_tax = $calculated_bill['admin_commision_with_tax'];
                $driver_wallet = $request_detail->driverDetail->driverWallet;
                $driver_wallet->amount_spent += $admin_commision_with_tax;
                $driver_wallet->amount_balance -= $admin_commision_with_tax;
                $driver_wallet->save();

                $driver_wallet_history = $request_detail->driverDetail->driverWalletHistory()->create([
                'amount'=>$admin_commision_with_tax,
                'transaction_id'=>str_random(6),
                'remarks'=>WalletRemarks::ADMIN_COMMISSION_FOR_REQUEST,
                'is_credit'=>false
            ]);
            }
        }
        // @TODO need to add driver commision if the payment type is wallet
        // Store Request bill

        $bill = $request_detail->requestBill()->create($calculated_bill);

        // Log::info($bill);

        $request_result = fractal($request_detail, new TripRequestTransformer)->parseIncludes(['requestBill','userDetail','driverDetail']);

        if ($request_detail->if_dispatch || $request_detail->user_id==null ) {
            goto dispatch_notify;
        }
        // Send Push notification to the user
        $user = $request_detail->userDetail;
        $title = trans('push_notifications.trip_completed_title');
        $body = trans('push_notifications.trip_completed_body');



        $pus_request_detail = $request_result->toJson();
        $push_data = ['notification_enum'=>PushEnums::DRIVER_END_THE_TRIP,'result'=>(string)$pus_request_detail];

        $socket_data = new \stdClass();
        $socket_data->success = true;
        $socket_data->success_message  = PushEnums::DRIVER_END_THE_TRIP;
        $socket_data->result = $request_result;
        // Form a socket sturcture using users'id and message with event name
        // $socket_message = structure_for_socket($user->id, 'user', $socket_data, 'trip_status');
        // dispatch(new NotifyViaSocket('transfer_msg', $socket_message));

        dispatch(new NotifyViaMqtt('trip_status_'.$user->id, json_encode($socket_data), $user->id));



        $title = trans('push_notifications.trip_completed_title');
        $body = trans('push_notifications.trip_completed_body');


              if($user->lang=='en'){

              $title ='Driver Ended the trip 😊';
              $body =  'Driver finished the ride, Please help us by rate the driver';

              }
              else{
                $title =  'السائق أنهى الرحلة 😊️';
                  $body ='السائق أنهى الرحلة ساعدنا للتقييم';
                }


        $user->notify(new AndroidPushNotification($title, $body));


           $title_en ='Driver Ended the trip 😊';
           $body_en =  'Driver finished the ride, Please help us by rate the driver';

                $title_ar =  'السائق أنهى الرحلة 😊️';
                  $body_ar ='السائق أنهى الرحلة ساعدنا للتقييم';

       $user->notify(new DriverfinishedtripNotification($title_ar,$title_en, $body_ar,$body_en));



        dispatch_notify:
        // @TODO Send email & sms









        return $this->respondSuccess($request_result, 'request_ended');
    }

    public function calculateDurationOfTrip($start_time)
    {
        $current_time = date('Y-m-d H:i:s');
        $start_time = Carbon::parse($start_time);
        $end_time = Carbon::parse($current_time);
        $totald_duration = $end_time->diffInMinutes($start_time);

        return $totald_duration;
    }

    /**
    * Calculate Ride fares
    *
    */
    public function calculateRideFares($zone_type_price, $distance, $duration, $waiting_time, $coupon_detail,$request_detail)
    {
        $request_place = $request_detail->requestPlace;

        $airport_surge = find_airport($request_place->pick_lat,$request_place->pick_lng);
        if($airport_surge==null){
            $airport_surge = find_airport($request_place->drop_lat,$request_place->drop_lng);
        }

        $airport_surge_fee = 0;

        if($airport_surge){

            $airport_surge_fee = $airport_surge->airport_surge_fee?:0;

        }


        // Distance Price
        $calculatable_distance = $distance - $zone_type_price->base_distance;
        $calculatable_distance = $calculatable_distance<0?0:$calculatable_distance;
        $distance_price = $calculatable_distance * $zone_type_price->price_per_distance;
        // Time Price
        $time_price = $duration * $zone_type_price->price_per_time;
        // Waiting charge
        $waiting_charge = $waiting_time * $zone_type_price->waiting_charge;
        // Base Price
        $base_price = $zone_type_price->base_price;

        // Sub Total

        if($request_detail->zoneType->vehicleType->is_support_multiple_seat_price && $request_detail->passenger_count > 0){

            if($request_detail->passenger_count ==1){
                $seat_discount = $request_detail->zoneType->vehicleType->one_seat_price_discount;
            }
            if($request_detail->passenger_count ==2){
                $seat_discount = $request_detail->zoneType->vehicleType->two_seat_price_discount;
            }
            if($request_detail->passenger_count ==3){
                $seat_discount = $request_detail->zoneType->vehicleType->three_seat_price_discount;
            }
            if($request_detail->passenger_count ==4){
                $seat_discount = $request_detail->zoneType->vehicleType->four_seat_price_discount;
            }

            // $price_discount = ($sub_total * ($seat_discount / 100));


            // $sub_total -= $price_discount;

            $base_price -= ($base_price * ($seat_discount / 100));

            $distance_price -=  ($distance_price * ($seat_discount / 100));

            $time_price -=  ($time_price * ($seat_discount / 100));

            $airport_surge_fee -= ($airport_surge_fee * ($seat_discount / 100));

        }

        $sub_total = $base_price+$distance_price+$time_price+$waiting_charge + $airport_surge_fee;


        // Check for Cancellation fee

        $cancellation_fee = RequestCancellationFee::where('user_id',$request_detail->user_id)->where('is_paid',0)->sum('cancellation_fee');

        if($cancellation_fee >0){

            RequestCancellationFee::where('user_id',$request_detail->user_id)->update([
                'is_paid'=>1,
                'paid_request_id'=>$request_detail->id]);

            $sub_total += $cancellation_fee;

        }

        $discount_amount = 0;
        if ($coupon_detail) {
            if ($coupon_detail->minimum_trip_amount < $sub_total) {
                $discount_amount = $sub_total * ($coupon_detail->discount_percent/100);
                if ($discount_amount > $coupon_detail->maximum_discount_amount) {
                    $discount_amount = $coupon_detail->maximum_discount_amount;
                }
                $sub_total = $sub_total - $discount_amount;
            }
        }

        // Get service tax percentage from settings
        $tax_percent = get_settings('service_tax');
        $tax_amount = ($sub_total * ($tax_percent / 100));
        // Get Admin Commision
        $service_fee = get_settings('admin_commission');
        // Admin commision
        $admin_commision = ($sub_total * ($service_fee / 100));

        //technical commission
         $technical_fee = get_settings('technical_commisssion');
        $technical_commisssion = ($sub_total * ($technical_fee / 100));



        // Admin commision with tax amount
        $admin_commision_with_tax = $tax_amount + $admin_commision;
        $driver_commision = $sub_total+$discount_amount;
        // Driver Commission
        if($coupon_detail && $coupon_detail->deduct_from==2){
            $driver_commision = $sub_total;
        }
        // Total Amount
        $total_amount = $sub_total + $admin_commision_with_tax+$technical_commisssion;

        return $result = [
        'base_price'=>$base_price,
        'base_distance'=>$zone_type_price->base_distance,
        'price_per_distance'=>$zone_type_price->price_per_distance,
        'distance_price'=>$distance_price,
        'price_per_time'=>$zone_type_price->price_per_time,
        'time_price'=>$time_price,
        'promo_discount'=>$discount_amount,
        'waiting_charge'=>$waiting_charge,
        'service_tax'=>$tax_amount,
        'service_tax_percentage'=>$tax_percent,
        'admin_commision'=>$admin_commision,
        'technical_commisssion'=>$technical_commisssion,
        'admin_commision_with_tax'=>$admin_commision_with_tax,
        'driver_commision'=>$driver_commision,
        'total_amount'=>$total_amount,
        'total_distance'=>$distance,
        'total_time'=>$duration,
        'airport_surge_fee'=>$airport_surge_fee,
        'cancellation_fee'=>$cancellation_fee
        ];
    }

       /**
    * Calculate Ride fares
    *
    */
    public function calculateRentalRideFares($zone_type_price, $distance, $duration, $waiting_time, $coupon_detail,$request_detail)
    {
        $request_place = $request_detail->requestPlace;

        $airport_surge = find_airport($request_place->pick_lat,$request_place->pick_lng);
        if($airport_surge==null){
            $airport_surge = find_airport($request_place->drop_lat,$request_place->drop_lng);
        }

        $airport_surge_fee = 0;

        if($airport_surge){

            $airport_surge_fee = $airport_surge->airport_surge_fee?:0;

        }


        // Distance Price
        $calculatable_distance = $distance - $zone_type_price->free_distance;
        $calculatable_distance = $calculatable_distance<0?0:$calculatable_distance;
        $distance_price = $calculatable_distance * $zone_type_price->distance_price_per_km;
        // Time Price
        $time_price = $duration * $zone_type_price->time_price_per_min;
        // Waiting charge
        $waiting_charge = $waiting_time * $zone_type_price->waiting_charge;
        // Base Price
        $base_price = $zone_type_price->base_price;

        // Sub Total

        if($request_detail->zoneType->vehicleType->is_support_multiple_seat_price && $request_detail->passenger_count > 0){

            if($request_detail->passenger_count ==1){
                $seat_discount = $request_detail->zoneType->vehicleType->one_seat_price_discount;
            }
            if($request_detail->passenger_count ==2){
                $seat_discount = $request_detail->zoneType->vehicleType->two_seat_price_discount;
            }
            if($request_detail->passenger_count ==3){
                $seat_discount = $request_detail->zoneType->vehicleType->three_seat_price_discount;
            }
            if($request_detail->passenger_count ==4){
                $seat_discount = $request_detail->zoneType->vehicleType->four_seat_price_discount;
            }

            // $price_discount = ($sub_total * ($seat_discount / 100));


            // $sub_total -= $price_discount;

            $base_price -= ($base_price * ($seat_discount / 100));

            $distance_price -=  ($distance_price * ($seat_discount / 100));

            $time_price -=  ($time_price * ($seat_discount / 100));

            $airport_surge_fee -= ($airport_surge_fee * ($seat_discount / 100));

        }

        $sub_total = $base_price+$distance_price+$time_price+$waiting_charge + $airport_surge_fee;


        $discount_amount = 0;

         if ($coupon_detail) {
            if ($coupon_detail->minimum_trip_amount < $sub_total) {

                $discount_amount = $sub_total * ($coupon_detail->discount_percent/100);
                if ($discount_amount > $coupon_detail->maximum_discount_amount) {
                    $discount_amount = $coupon_detail->maximum_discount_amount;
                }

                $sub_total = $sub_total - $discount_amount;
            }
        }

        // Get service tax percentage from settings
        $tax_percent = get_settings('service_tax');
        $tax_amount = ($sub_total * ($tax_percent / 100));
        // Get Admin Commision
        $service_fee = get_settings('admin_commission');
        // Admin commision
        $admin_commision = ($sub_total * ($service_fee / 100));
        // Admin commision with tax amount
        $admin_commision_with_tax = $tax_amount + $admin_commision;
        $driver_commision = $sub_total+$discount_amount;
        // Driver Commission
        if($coupon_detail && $coupon_detail->deduct_from==2){
            $driver_commision = $sub_total;
        }
        // Total Amount
        $total_amount = $sub_total + $admin_commision_with_tax;

        return $result = [
        'base_price'=>$base_price,
        'base_distance'=>$zone_type_price->free_distance,
        'price_per_distance'=>$zone_type_price->distance_price_per_km,
        'distance_price'=>$distance_price,
        'price_per_time'=>$zone_type_price->time_price_per_min,
        'time_price'=>$time_price,
        'promo_discount'=>$discount_amount,
        'waiting_charge'=>$waiting_charge,
        'service_tax'=>$tax_amount,
        'service_tax_percentage'=>$tax_percent,
        'admin_commision'=>$admin_commision,
        'technical_commisssion'=>$technical_commisssion,

        'admin_commision_with_tax'=>$admin_commision_with_tax,
        'driver_commision'=>$driver_commision,
        'total_amount'=>$total_amount,
        'total_distance'=>$distance,
        'total_time'=>$duration,
        'airport_surge_fee'=>$airport_surge_fee
        ];
    }

    /**
    * Validate & Apply Promo code
    *
    */
    public function validateAndGetPromoDetail($promo_code_id)
    {
       // Validate if the promo is expired
        $current_date = Carbon::today()->toDateTimeString();

        $expired = Promo::where('id', $promo_code_id)->where('from', '<=', $current_date)->orWhere('to', '>=', $current_date)->first();


        return $expired;

        // $exceed_usage = PromoUser::where('promo_code_id', $expired->id)->where('user_id', $user_id)->get()->count();

        // if ($exceed_usage >= $expired->uses_per_user) {
        //     return null;
        // }

        // if ($expired->total_uses > $expired->total_uses+1) {
        //     return null;
        // }

    }
}
