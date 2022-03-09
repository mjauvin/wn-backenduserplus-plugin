<?php namespace StudioAzura\BackendUserPlus;

use App;
use Config;
use Backend;
use Backend\Models\User as UserModel;
use Backend\Models\UserRole;
use Backend\Controllers\Users as UsersController;
use Event;
use Lang;
use Mail;
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
    }

    protected function extendBackendUserModel()
    {
        UserModel::extend(function ($model) {
            unset($model->rules['password']);
            unset($model->rules['password_confirmation']);

            if (PluginManager::instance()->exists('Winter.Translate')) {
                if (!$model->propertyExists('translatable')) {
                    $model->addDynamicProperty('translatable', []);
                    $model->addPurgeable('translatable');
                }
                $model->translatable = array_merge($model->translatable, ['position']);

                if (!$model->isClassExtendedWith('Winter\Translate\Behaviors\TranslatableModel')) {
                    $model->extendClassWith('Winter\Translate\Behaviors\TranslatableModel');
                }
            }

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

                # add position field
                $widget->tabs['fields']['position'] = [
                    'label' => 'studioazura.backenduserplus::lang.labels.title',
                    'tab'   => 'backend::lang.user.account',
                    'type'  => 'text',
                ];

                $trigger = [
                    'action' => 'hide',
                    'field' => 'send_invite',
                    'condition' => 'value[reset][none]',
                ];
                $widget->tabs['fields']['password']['trigger'] = $trigger;
                $widget->tabs['fields']['password_confirmation']['trigger'] = $trigger;
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
                if (isset($model->send_invite) && $model->send_invite !== 'reset' || !isset($model->send_invite) && !empty($model->password)) {
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
}
