<?php namespace StudioAzura\BackendUserPlus;

use App;
use Backend;
use BackendAuth;
use Backend\Models\User as UserModel;
use Backend\Models\UserRole;
use Backend\Controllers\Users as UsersController;
use Cache;
use Carbon\Carbon;
use Config;
use Event;
use Flash;
use Lang;
use Mail;
use Redirect;
use Request;
use Session;
use System\Classes\MailManager;
use System\Classes\PluginBase;
use System\Classes\PluginManager;

/**
 * BackendUserPlus Plugin Information File
 */
class Plugin extends PluginBase
{
    public function pluginDetails()
    {
        return [
            'name'        => 'BackendUserPlus',
            'description' => 'studioazura.backenduserplus::lang.description',
            'author'      => 'StudioAzura',
            'icon'        => 'icon-user-plus'
        ];
    }

    public function boot()
    {
        $this->extendBackendUserModel();
        $this->extendBackendUserController();
        $this->addRecordLock();
    }

    protected function extendBackendUserModel()
    {
        UserModel::extend(function ($model) {
            unset($model->rules['password']);
            unset($model->rules['password_confirmation']);

            if (PluginManager::instance()->exists('Winter.Translate')) {
                $model->addPurgeable('translatable');
                $model->addDynamicProperty('translatable', ['position', 'description']);

                if (!$model->isClassExtendedWith('Winter\Translate\Behaviors\TranslatableModel')) {
                    $model->extendClassWith('Winter\Translate\Behaviors\TranslatableModel');
                }
            }

            $model->bindEvent('model.form.filterFields', function ($formWidget, $fields, $context) {
                if ($context === 'update') {
                    $fields->password->hidden = $fields->password_confirmation->hidden = true;
                    if (isset($fields->_changePassword) && $fields->_changePassword->value) {
                        $fields->password->hidden = false;
                        $fields->password_confirmation->hidden = false;
                    }
                }
                else if ($context === 'create') {
                    if (isset($fields->send_invite) && in_array($fields->send_invite->value, ['none','reset'])) {
                        $fields->password->hidden = true;
                        $fields->password_confirmation->hidden = true;
                    }
                }
            });

            // modify Backend User fields
            Event::listen('backend.form.extendFieldsBefore', function ($widget) {
                if (!$widget->model instanceof UserModel || !$widget->getController() instanceof UsersController) {
                    return;
                }
                $widget->tabs['fields']['send_invite']['type'] = 'radio';
                $widget->tabs['fields']['send_invite']['options'] = [
                    'credentials' => 'studioazura.backenduserplus::lang.labels.send-invite.credentials',
                    'reset' => 'studioazura.backenduserplus::lang.labels.send-invite.reset',
                    'none' => 'studioazura.backenduserplus::lang.labels.send-invite.do-not-send',
                ];
                $widget->tabs['fields']['send_invite']['default'] = 'credentials';

                $widget->tabs['icons']['studioazura.backenduserplus::lang.tabs.meta'] = 'icon-id-card';

                # add change password checkbox
                $widget->tabs['fields']['_changePassword'] = [
                    'label' => 'studioazura.backenduserplus::lang.labels.changePassword',
                    'tab'   => 'backend::lang.user.account',
                    'type'  => 'checkbox',
                    'context' => 'update',
                ];

                # add position field
                $widget->tabs['fields']['position'] = [
                    'label' => 'studioazura.backenduserplus::lang.labels.title',
                    'tab'   => 'studioazura.backenduserplus::lang.tabs.meta',
                    'type'  => 'text',
                    'span' => 'left',
                ];

                # add description field
                $widget->tabs['fields']['description'] = [
                    'label' => 'studioazura.backenduserplus::lang.labels.description',
                    'tab'   => 'studioazura.backenduserplus::lang.tabs.meta',
                    'type'  => 'textarea',
                ];

                $widget->tabs['fields']['password']['dependsOn'] = ['_changePassword', 'send_invite'];
                $widget->tabs['fields']['password_confirmation']['dependsOn'] = ['_changePassword', 'send_invite'];
            });

            // send invite email or restore email
            $model->bindEvent('model.afterCreate', function () use ($model) {
                $model->restorePurgedValues();

                if (isset($model->send_invite) && $model->send_invite != 'none') {
                    $model->sendCustomInvite();
                }
                $model->send_invite = null;
                $model->purgeAttributes('send_invite');
            }, 10);

            // no password required when sending a reset link by email
            $model->bindEvent('model.beforeValidate', function () use ($model) {
                if (isset($model->send_invite) && $model->send_invite === 'none') {
                    $passwd = md5(time());
                    $model->password = $model->password_confirmation = $passwd;
                }
                if ((isset($model->send_invite) && $model->send_invite !== 'reset') || (!isset($model->send_invite) && !$model->originalIsEquivalent('password'))) {
                    $model->rules['password'] = 'required|min:8';
                    $model->rules['password_confirmation'] = 'required_with:password|same:password';
                }
            });

            $model->addDynamicMethod('sendCustomInvite', function () use ($model) {
                if ($model->send_invite === 'reset') {
                    $code = $model->getResetPasswordCode();
                    $link = Backend::url('backend/auth/reset/' . $model->id . '/' . $code);
                    $password = null;
                } else {
                    $link = Backend::url('backend');
                    $password = $model->getOriginalHashValue('password');
                }

                $data = [
                    'firstname' => $model->first_name,
                    'lastname' => $model->last_name,
                    'name' => $model->full_name,
                    'login' => $model->login,
                    'password' => $password,
                    'link' => $link,
                ];

                Mail::send('studioazura.backenduserplus::mail.invite', $data, function ($message) use ($model) {
                    $message->to($model->email, $model->full_name);
                });
            });
        });
    }

    protected function extendBackendUserController()
    {
        UsersController::extend(function ($controller) {
            list($author, $plugin) = explode('\\', strtolower(get_class()));
            $partials_path = sprintf('$/%s/%s/partials/users', $author, $plugin);
            $controller->addViewPath($partials_path);

            $controller->addDynamicMethod('onPurgeDeleted', function () use ($controller) {
                UserModel::onlyTrashed()->forceDelete();
                return $controller->listRefresh();
            });
        });
    }

    protected function addRecordLock()
    {
        Event::listen('backend.page.beforeDisplay', function ($controller, $action, $params) {
            if (!method_exists($controller, 'formFindModelObject')) {
                return;
            }
            if ($action === 'update') {
                $recordId = $params[0];
                if (! $model = $controller->formFindModelObject($recordId)) {
                    return;
                }
                $controller->initForm($model);

                $cacheKey = sprintf("%s-%d", str_replace('\\', '-', get_class($model)), $recordId);

                $cacheValue = [
                    'ip' => Request::ip(),
                    'token' => Session::get('_token'),
                    'user' => BackendAuth::getUser()?->login,
                    'ts' => Carbon::now()->timestamp,
                ];

                if (!$lock = Cache::get($cacheKey)) {
                    Cache::put($cacheKey, $cacheValue, 5*60);
                    if ($lastLock = Session::get('lastLock')) {
                        Cache::forget($lastLock);
                    }
                    Session::put('lastLock', $cacheKey);
                }
                elseif ($lock['token'] !== $cacheValue['token']) {
                    $ts = (new Carbon(array_get($lock, 'ts')))->toDateTimeString();
                    $user = strtoupper(array_get($lock, 'user'));
                    Flash::error("Access DENIED, user {$user} is updating the record.");
                    return Redirect::back();
                }
            }
            else if ($cacheKey = Session::pull('lastLock')) {
                Cache::forget($cacheKey);
            }
        });
    }
}
