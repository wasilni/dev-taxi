<?php

namespace App\Transformers\User;

use App\Models\User;
use App\Base\Constants\Auth\Role;
use App\Transformers\Transformer;
use App\Transformers\Access\RoleTransformer;
use App\Transformers\Requests\TripRequestTransformer;
use App\Models\Admin\Sos;
use App\Transformers\Common\SosTransformer;
use App\Transformers\User\FavouriteLocationsTransformer;


class UserTransformer extends Transformer
{
    /**
     * Resources that can be included if requested.
     *
     * @var array
     */


      public function __construct()
    {
    	$this->availableIncludes = [
        'roles','onTripRequest','metaRequest','favouriteLocations'        ];

        	$this->defaultIncludes = [
        'sos'
        ];
    }




    /**
     * Resources that can be included default.
     *
     * @var array
     */


    /**
     * A Fractal transformer.
     *
     * @return array
     */
    public function transform(User $user)
    {
        $params = [
            'id' => $user->id,
            'name' => $user->name,
            'last_name' => $user->last_name,
            'username' => $user->username,
            'email' => $user->email,
            'mobile' => $user->countryDetail->dial_code.$user->mobile,
            'profile_picture' => $user->profile_picture,
            'active' => $user->active,
            'email_confirmed' => $user->email_confirmed,
            'mobile_confirmed' => $user->mobile_confirmed,
            'last_known_ip' => $user->last_known_ip,
            'last_login_at' => $user->last_login_at,
            'rating' => round($user->rating, 2),
            'no_of_ratings' => $user->no_of_ratings,
            'refferal_code'=>$user->refferal_code,
            'currency_code'=>$user->countryDetail->currency_code,
            'currency_symbol'=>$user->countryDetail->currency_symbol,
            //'map_key'=>get_settings('google_map_key'),
            'mqtt_ip'=>'54.172.163.200',
            'show_rental_ride'=>true,
            'gender' =>$user->gender,
             'points_balance' =>$user->points_balance,
             'referred_by'=>$user->referred_by,
             'level_ar'=>$user->level_ar,
              'level_en'=>$user->level_en,



            // 'created_at' => $user->converted_created_at->toDateTimeString(),
            // 'updated_at' => $user->converted_updated_at->toDateTimeString(),
        ];

        $referral_comission = get_settings('referral_commision_for_user');
          if($user->lang=='en'){
       $referral_comission_string = 'Refer a friend and earn'.$user->countryDetail->currency_symbol.''.$referral_comission;

       }
    else{
        $referral_comission_string = 'ادع صديق واكسب'.$user->countryDetail->currency_symbol.''.$referral_comission;
       }



        $params['referral_comission_string'] = $referral_comission_string;
        return $params;
    }

    /**
     * Include the roles of the user.
     *
     * @param User $user
     * @return \League\Fractal\Resource\Collection|\League\Fractal\Resource\NullResource
     */
    public function includeRoles(User $user)
    {
        $roles = $user->roles;

        return $roles
        ? $this->collection($roles, new RoleTransformer)
        : $this->null();
    }
    /**
     * Include the request of the user.
     *
     * @param User $user
     * @return \League\Fractal\Resource\Collection|\League\Fractal\Resource\NullResource
     */
    public function includeOnTripRequest(User $user)
    {
        $request = $user->requestDetail()->where('is_cancelled', false)->where('user_rated', false)->where('driver_id', '!=', null)->first();

        return $request
        ? $this->item($request, new TripRequestTransformer)
        : $this->null();
    }
    /**
    * Include the request meta of the user.
    *
    * @param User $user
    * @return \League\Fractal\Resource\Collection|\League\Fractal\Resource\NullResource
    */
    public function includeMetaRequest(User $user)
    {
        $request = $user->requestDetail()->where('is_completed', false)->where('is_cancelled', false)->where('user_rated', false)->where('driver_id', null)->where('is_later', 0)->first();

        return $request
        ? $this->item($request, new TripRequestTransformer)
        : $this->null();
    }

     /**
    * Include the request meta of the user.
    *
    * @param User $user
    * @return \League\Fractal\Resource\Collection|\League\Fractal\Resource\NullResource
    */
    public function includeSos(User $user)
    {
        $request = Sos::select('id', 'name', 'number', 'user_type', 'created_by')
        ->where('created_by', auth()->user()->id)
        ->orWhere('user_type', 'admin')
        ->orderBy('created_at', 'Desc')
        ->companyKey()->get();

        return $request
        ? $this->collection($request, new SosTransformer)
        : $this->null();
    }

    /**
     * Include the favourite location of the user.
     *
     * @param User $user
     * @return \League\Fractal\Resource\Collection|\League\Fractal\Resource\NullResource
     */
    public function includeFavouriteLocations(User $user)
    {
        $fav_locations = $user->favouriteLocations;

        return $fav_locations
        ? $this->collection($fav_locations, new FavouriteLocationsTransformer)
        : $this->null();
    }
}
