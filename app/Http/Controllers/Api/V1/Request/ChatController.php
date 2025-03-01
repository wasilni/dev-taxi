<?php

namespace App\Http\Controllers\Api\V1\Request;

use App\Models\Request\Chat;
use App\Base\Constants\Auth\Role;
use App\Http\Controllers\Api\V1\BaseController;
use App\Models\Request\Request as RequestModel;
use App\Base\Constants\Masters\PushEnums;
use App\Jobs\Notifications\AndroidPushNotification;
use App\Jobs\NotifyViaMqtt;
use Illuminate\Http\Request;
use App\Notifications\NewMessageNotification;


/**
 * @group Request-Chat
 *
 * APIs for In app chat b/w user/driver
 */
class ChatController extends BaseController
{

    protected $chat;

    function __construct(Chat $chat)
    {
        $this->chat = $chat;
    }


    /**
     * Chat history for both user & driver
     *
     */
    public function history(RequestModel $request)
    {

        $chats = $request->requestChat()->orderBy('created_at', 'asc')->get();

        if (access()->hasRole(Role::USER)) {
            $from_type = 1;
        } else {
            $from_type = 2;
        }
        foreach ($chats as $key => $chat) {
            if ($chat->from_type == $from_type) {

                $chats[$key]['message_status'] = 'send';
            } else {
                $chats[$key]['message_status'] = 'receive';
            }
        }

        return $this->respondSuccess($chats, 'chats_listed');
    }

    /**
     * Update Seen
     * 
     * 
     * */
    public function updateSeen(Request $request){

        if (access()->hasRole(Role::USER)) {
            $seen_from_type = 2;
        } else {
            $seen_from_type = 1;
        }

        $request_detail = RequestModel::find($request->request_id);

        $request_detail->requestChat()->where('from_type',$seen_from_type)->update(['seen'=>true]);

        return $this->respondSuccess(null, 'message_seen_successfully');


    }

    /**
     * Send Chat Message
     * @bodyParam request_id uuid required request id of the trip
     * @bodyParam message string required message of chat
     */
    public function send(Request $request)
    {
        if (access()->hasRole(Role::USER)) {
            $from_type = 1;
        } else {
            $from_type = 2;
        }

        $request_detail = RequestModel::find($request->request_id);

        $request_detail->requestChat()->create([
            'message' => $request->message,
            'from_type' => $from_type,
            'user_id' => auth()->user()->id
        ]);

        $chats = $request_detail->requestChat()->orderBy('created_at', 'asc')->get();


        if (access()->hasRole(Role::USER)) {
            $from_type = 1;
            $user_type = 'user';
            $driver = $request_detail->driverDetail;
            $notifable_driver = $driver->user;
        } else {
            $from_type = 2;
            $user_type = 'driver';
            $driver = $request_detail->userDetail;
            $notifable_driver = $driver;
        }
        foreach ($chats as $key => $chat) {
            if ($chat->from_type == $from_type) {

                $chats[$key]['message_status'] = 'receive';
            } else {
                $chats[$key]['message_status'] = 'send';


            }
        }


        $socket_data = new \stdClass();
        $socket_data->success = true;
        $socket_data->success_message  = PushEnums::NEW_MESSAGE;
        $socket_data->data = $chats;

        dispatch(new NotifyViaMqtt('new_message_' . $driver->id, json_encode($socket_data), $driver->id));



      if($notifable_driver->lang=='en'){
       $title ='New Message From ' . $driver->name;
       $body = $request->message;

       }
    else{
        $title = 'مسج جديد من    ' . $driver->name;
        $body = $request->message;
    }




        $notifable_driver->notify(new AndroidPushNotification($title, $body));
        
        
        

        
      $title_ar =  'مسج جديد من    ' . $driver->name;
      $body_ar =  $request->message;
      $title_en ='New Message From ' . $driver->name;
      $body_en =  $request->message;
      
      
        $notifable_driver->notify(new NewMessageNotification($title_ar,$title_en,$body_ar, $body_en));
        
        
        

        return $this->respondSuccess(null, 'message_sent_successfully');
    }
}
