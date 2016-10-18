<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Http\Requests;
use App\Http\Controllers\Controller;
use App\House;
use App\User;
use App\Http\Requests\CreateEnrolmentRequest;
use Auth;
use App\Enrolment;
use DateTime;
use App\Role;

class HouseUserController extends Controller
{
    public function __construct(){
        $this->middleware('auth0.jwt');
}
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index($id)
    {
        $house = House::whereId($id)->with('enrolledUsers.roles')->get();
        if (!$house) {
            return response()->json(['message' => 'This class does not exist', 'code'=>404], 404);
        }
        return $house;        
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(CreateEnrolmentRequest $request, House $houses)
    {   
        //check if it is student self-enrolling, then check for mastercode. If are places left then update. If no more places, give a warning.
        if ($request->mastercode) {
            return $this->selfEnrol($request->mastercode, $houses);  // self-enrol with mastercode
        }
        $user = Auth::user();
        $date = new DateTime('now');
        $enrol_user  = User::findorfail($request->user_id);
        $most_powerful = $user->enrolledClasses()->whereHouseId($houses->id)->with('roles')->min('role_id');
        $role_to_enrol = Role::where('role','LIKE',$request->role)->first();
        if (!$role_to_enrol) {
            return response()->json(['message'=>'Role does not exist.', 'code'=>404], 404);
        }
        if ($most_powerful > $role_to_enrol->id && !$user->is_admin) {        // administrator 
            return response()->json(['message'=>'No authorization to enrol', 'code'=>203], 203);
        }
        
        $user_id = $enrol_user ? $enrol_user->id : $user->id; 
        return $this->adminEnrol($user_id, $houses, $role_to_enrol->id); // admin enrolling others any role
    }

    /**
     *  Called by $this->store function.
     *
     * @param  \Illuminate\Http\Request  $mastercode, $houses
     * @return \Illuminate\Http\Response
     */
    public function selfEnrol($mastercode, House $houses) {
        $user = Auth::user();
        $check_mastercode = Enrolment::whereMastercode($mastercode)->first();
        if (!$check_mastercode) return response()->json(['message'=>'Your mastercode is wrong.', 'code'=>404], 404);
        $date = new DateTime('now');
        if ($check_mastercode->places_alloted) {
            $check_mastercode->places_alloted -= 1;
            $mastercode = $check_mastercode->places_alloted < 1 ? null : $mastercode;
            $check_mastercode->fill(['mastercode'=>$mastercode])->save();
            $enrolment = Enrolment::firstOrNew(['user_id'=>$user->id, 'house_id'=>$houses->id, 'role_id'=>6]);
            $enrolment->fill(['start_date'=>$date,'expiry_date'=>$date->modify('+1 year'), 'payment_email'=>$check_mastercode->payment_email, 'purchaser_id'=>$check_mastercode->user_id])->save();
            return response()->json(['message'=>'Your mastercode has been accepted and your enrolment is successful.', 'code'=>201], 201);
        }
        return response()->json(['message'=>'There is no more places left for this mastercode.', 'code'=>404], 404);
    }

    /**
     * Enrolment by admin or user enrolled in the class with super access: Principal, teacher, 
     * Department Head  - no mastercode required     
     *
     * @param  \Illuminate\Http\Request  $enrol_user, House, Role->role
     * @return \Illuminate\Http\Response
     */
    public function adminEnrol($enrol_user, House $houses, $role) {
        $user = Auth::user();
        $date = new DateTime('now');
        $enrolment = Enrolment::firstOrNew(['user_id'=>$enrol_user, 'house_id'=>$houses->id, 'role_id'=>$role]);
        $enrolment->fill(['start_date'=>$date, 'payment_email'=>$user->email,'expiry_date'=>$date->modify('+1 year')])->save();
        return response()->json(['message'=>'Enrolment successful.', 'code'=>201], 201);
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id, $users)
    {
        $house = \App\House::find($id);
        if (!$house) {
            return response()->json(['message' => 'This class does not exist', 'code'=>404], 404);
        }
        return $house->enrolledUsers()->get();
                
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(CreateEnrolmentRequest $request, House $houses, User $users)
    {
        $msg = "";
        $most_powerful = $users->enrolledClasses()->whereHouseId($houses->id)->with('roles')->min('role_id');
        $role_to_enrol = Role::where('role','LIKE',$request->role)->first();
        if (!$role_to_enrol) {
            return response()->json(['message'=>'Role does not exist.', 'code'=>404], 404);
        }
        if ($most_powerful > $role_to_enrol->id && !$user->is_admin) {        // administrator 
            return response()->json(['message'=>'No authorization to enrol', 'code'=>203], 203);
        }
        try {
            //unenrol user to the house in house_role_user
            $houses->unenrollUser($users->id, $role_to_enrol->id);
            $msg = 'Successfully unenrolled';
        }
        catch(\Exception $exception) {
            $msg = 'Unenrolment cannot be done.'.$exception;
        }
        return response()->json(['message'=>$msg, 'code'=>201], 201);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy(House $houses, User $users)
    {
        $most_powerful = $users->enrolledClasses()->whereHouseId($houses->id)->with('roles')->min('role_id');
        $role_to_enrol = Role::where('role','LIKE',$request->role)->first();
        if (!$role_to_enrol) {
            return response()->json(['message'=>'Role does not exist.', 'code'=>404], 404);
        }
        if ($most_powerful > $role_to_enrol->id && !$user->is_admin) {        // administrator 
            return response()->json(['message'=>'No authorization to enrol', 'code'=>203], 203);
        }
        try {
            $houses->enrolledUsers()->detach($users);
        } catch(\Exception $exception){
            return response()->json(['message'=>'Unable to remove user from class', 'code'=>500], 500);
        }
        return response()->json(['message'=>'User removed successfully', 'code'=>201],201);
    }
}