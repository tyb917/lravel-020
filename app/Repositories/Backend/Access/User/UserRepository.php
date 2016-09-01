<?php

namespace App\Repositories\Backend\Access\User;

use App\Exceptions\GeneralException;
use App\Models\Access\User\User;
use App\Repositories\Backend\Access\Role\RoleInterface;
use Illuminate\Support\Facades\DB;

/**
 * Class EloquentUserRepository
 * @package App\Repositories\User
 */
class UserRepository implements UserInterface
{
    /**
     * @var RoleInterface
     */
    protected $role;

    /**
     * @var FrontendUserInterface
     */
    protected $user;

    /**
     * @param RoleInterface $role
     * @param FrontendUserInterface $user
     */
    public function __construct(RoleInterface $role, User $user)
    {
        $this->role = $role;
        $this->user = $user;
    }

    /**
     * @param int $status
     * @param bool $trashed
     * @return mixed
     */
    public function getForDataTable()
    {   
        return User::leftJoin("role_user",'role_user.user_id','=','users.id')
            ->whereNotNull('role_user.user_id')
            ->get()
            ->unique();
    }

    /**
     * @param  $input
     * @param  $roles
     * @throws GeneralException
     * @throws UserNeedsRolesException
     * @return bool
     */
    public function create($input)
    {
        $user = User::where('name', $input['name'])->first();

        if (!$user) throw new GeneralException('用户不存在！');

        if ($user->id == 1) {
            throw new GeneralException('创始人不允许更改！');
        }
        
        $all = $input['role_user'] == 'all' ? true : false;

        if (! isset($input['roles'])) $input['roles'] = [];

        $roles = [];
        if (! $all) {
            if (config('entrust.users.user_must_contain_role') && count($input['roles']) == 0) {
                throw new GeneralException('您必须为管理员至少选择一个角色！');
            }
            if (is_array($input['roles']) && count($input['roles'])) {
                foreach ($input['roles'] as $role) {
                    if (is_numeric($role)) {
                        array_push($roles, $role);
                    }
                }
            }
        } else {
            $roles[] = 2;
        }

		DB::transaction(function() use ($user, $roles) {
            try {
                $user->attachRoles($roles);
                return true;
            } catch (\Exception $e) {
                throw new GeneralException('管理员创建失败！');
            }
		});
    }

    /**
     * @param User $user
     * @param $input
     * @param $roles
     * @return bool
     * @throws GeneralException
     */
    public function update($input)
    {
        $user = User::where('name', $input['name'])->first();

        if (!$user) throw new GeneralException('用户不存在！');

        if ($user->id == 1) {
            throw new GeneralException('创始人不允许更改！');
        }
        
        $all = $input['role_user'] == 'all' ? true : false;

        if (! isset($input['roles'])) $input['roles'] = [];

        $roles = [];
        if (! $all) {
            if (config('entrust.users.user_must_contain_role') && count($input['roles']) == 0) {
                throw new GeneralException('您必须为管理员至少选择一个角色！');
            }
            if (is_array($input['roles']) && count($input['roles'])) {
                foreach ($input['roles'] as $role) {
                    if (is_numeric($role)) {
                        array_push($roles, $role);
                    }
                }
            }
        } else {
            $roles[] = 2;
        }

        DB::transaction(function() use ($user, $roles) {
            try {
                $this->flushRoles($roles, $user);
                return true;
            } catch (\Exception $e) {
                throw new GeneralException('管理员编辑失败！');
            }
        });
    }

    /**
     * @param  User $user
     * @param  $input
     * @throws GeneralException
     * @return bool
     */
    public function updatePassword(User $user, $input)
    {
        $user->password = bcrypt($input['password']);

        if ($user->save()) {
            event(new UserPasswordChanged($user));
            return true;
        }

        throw new GeneralException(trans('exceptions.backend.access.users.update_password_error'));
    }

    /**
     * @param  User $user
     * @throws GeneralException
     * @return bool
     */
    public function destroy($id)
    {
        //Would be stupid to delete the administrator role
        if ($id == 1) { //id is 1 because of the seeder
            throw new GeneralException('创始人不允许删除！');
        }

        if (auth()->id() == $id) {
            throw new GeneralException('不能删除自己！');
        }
        $user = User::with('roles')->find($id);
        $user->detachRoles($user->roles);
        return true;
    }

    /**
     * @param  User $user
     * @throws GeneralException
     * @return boolean|null
     */
    public function delete(User $user)
    {
        //Failsafe
        if (is_null($user->deleted_at)) {
            throw new GeneralException("This user must be deleted first before it can be destroyed permanently.");
        }

		DB::transaction(function() use ($user) {
			//Detach all roles & permissions
			$user->detachRoles($user->roles);

			if ($user->forceDelete()) {
				event(new UserPermanentlyDeleted($user));
				return true;
			}

			throw new GeneralException(trans('exceptions.backend.access.users.delete_error'));
		});
    }

    /**
     * @param  User $user
     * @throws GeneralException
     * @return bool
     */
    public function restore(User $user)
    {
        //Failsafe
        if (is_null($user->deleted_at)) {
            throw new GeneralException("This user is not deleted so it can not be restored.");
        }

        if ($user->restore()) {
            event(new UserRestored($user));
            return true;
        }

        throw new GeneralException(trans('exceptions.backend.access.users.restore_error'));
    }

    /**
     * @param  User $user
     * @param  $status
     * @throws GeneralException
     * @return bool
     */
    public function mark(User $user, $status)
    {
        if (access()->id() == $user->id && $status == 0) {
            throw new GeneralException(trans('exceptions.backend.access.users.cant_deactivate_self'));
        }

        $user->status = $status;

        //Log history dependent on status
        switch ($status) {
            case 0:
                event(new UserDeactivated($user));
            break;

            case 1:
                event(new UserReactivated($user));
            break;
        }

        if ($user->save()) {
            return true;
        }

        throw new GeneralException(trans('exceptions.backend.access.users.mark_error'));
    }

    /**
     * @param User $user
     * @return \Illuminate\Http\RedirectResponse
     * @throws GeneralException
     */
    public function loginAs(User $user)
    {
        // Overwrite who we're logging in as, if we're already logged in as someone else.
        if (session()->has('admin_user_id') && session()->has('temp_user_id')) {
            // Let's not try to login as ourselves.
            if (auth()->id() == $user->id || session()->get('admin_user_id') == $user->id) {
                throw new GeneralException('Do not try to login as yourself.');
            }

            // Overwrite temp user ID.
            session(['temp_user_id' => $user->id]);

            // Login.
            access()->loginUsingId($user->id);

            // Redirect.
            return redirect()->route("frontend.index");
        }

        $this->flushTempSession();

        //Won't break, but don't let them "Login As" themselves
        if (access()->id() == $user->id) {
            throw new GeneralException("Do not try to login as yourself.");
        }

        //Add new session variables
        session(["admin_user_id" => access()->id()]);
        session(["admin_user_name" => access()->user()->name]);
        session(["temp_user_id" => $user->id]);

        //Login user
        access()->loginUsingId($user->id);

        //Redirect to frontend
        return redirect()->route("frontend.index");
    }

    /**
     * @return \Illuminate\Http\RedirectResponse
     */
    public function logoutAs()
    {

        //If for some reason route is getting hit without someone already logged in
        if (! access()->user()) {
            return redirect()->route("auth.login");
        }

        //If admin id is set, relogin
        if (session()->has("admin_user_id") && session()->has("temp_user_id")) {
            //Save admin id
            $admin_id = session()->get("admin_user_id");

            $this->flushTempSession();

            //Relogin admin
            access()->loginUsingId((int)$admin_id);

            //Redirect to backend user page
            return redirect()->route("admin.access.user.index");
        } else {
            $this->flushTempSession();

            //Otherwise logout and redirect to login
            access()->logout();
            return redirect()->route("auth.login");
        }
    }

	/**
	 * Remove old session variables from admin logging in as user
	 */
	public function flushTempSession()
	{
		//Remove any old session variables
		session()->forget("admin_user_id");
		session()->forget("admin_user_name");
		session()->forget("temp_user_id");
	}

    /**
     * Check to make sure at lease one role is being applied or deactivate user
     *
     * @param  $user
     * @param  $roles
     * @throws UserNeedsRolesException
     */
    private function validateRoleAmount($user, $roles)
    {
        //Validate that there's at least one role chosen, placing this here so
        //at lease the user can be updated first, if this fails the roles will be
        //kept the same as before the user was updated
        if (count($roles) == 0) {
            //Deactivate user
            $user->status = 0;
            $user->save();

            $exception = new UserNeedsRolesException();
            $exception->setValidationErrors(trans('exceptions.backend.access.users.role_needed_create'));

            //Grab the user id in the controller
            $exception->setUserID($user->id);
            throw $exception;
        }
    }

    /**
     * @param  $input
     * @param  $user
     * @throws GeneralException
     */
    private function checkUserByEmail($input, $user)
    {
        //Figure out if email is not the same
        if ($user->email != $input['email']) {
            //Check to see if email exists
            if (User::where('email', '=', $input['email'])->first()) {
                throw new GeneralException(trans('exceptions.backend.access.users.email_error'));
            }
        }
    }

    /**
     * @param $roles
     * @param $user
     */
    private function flushRoles($roles, $user)
    {
        //Flush roles out, then add array of new ones
        $user->detachRoles($user->roles);
        $user->attachRoles($roles);
    }

    /**
     * @param  $roles
     * @throws GeneralException
     */
    private function checkUserRolesCount($roles)
    {
        //User Updated, Update Roles
        //Validate that there's at least one role chosen
        if (count($roles['role_user']) == 0) {
            throw new GeneralException(trans('exceptions.backend.access.users.role_needed'));
        }
    }

    /**
     * @param  $input
     * @return mixed
     */
    private function createUserStub($input)
    {
        $user                    = new User;
        $user->name              = $input['name'];
        $user->email             = $input['email'];
        $user->password          = bcrypt($input['password']);
        $user->status            = isset($input['status']) ? 1 : 0;
        $user->confirmation_code = md5(uniqid(mt_rand(), true));
        $user->confirmed         = isset($input['confirmed']) ? 1 : 0;
        return $user;
    }

}
