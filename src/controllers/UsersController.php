<?php
namespace Craft;

/**
 * Handles user account related tasks.
 */
class UsersController extends BaseController
{
	protected $allowAnonymous = array('actionLogin', 'actionForgotPassword', 'actionValidate', 'actionSetPassword', 'actionSaveUser');

	/**
	 * Displays the login template, and handles login post requests.
	 */
	public function actionLogin()
	{
		if (craft()->userSession->isLoggedIn())
		{
			$this->redirect('');
		}

		$vars = array();

		if (craft()->request->isPostRequest())
		{
			$loginName = craft()->request->getPost('loginName');
			$password = craft()->request->getPost('password');
			$rememberMe = (bool) craft()->request->getPost('rememberMe');

			if (craft()->userSession->login($loginName, $password, $rememberMe))
			{
				if (craft()->request->isAjaxRequest())
				{
					$this->returnJson(array(
						'success' => true
					));
				}
				else
				{
					craft()->userSession->setNotice(Craft::t('Logged in.'));
					$this->redirectToPostedUrl();
				}
			}
			else
			{
				$errorCode = craft()->userSession->getLoginErrorCode();
				$errorMessage = craft()->userSession->getLoginErrorMessage($errorCode);

				if (craft()->request->isAjaxRequest())
				{
					$this->returnJson(array(
						'errorCode' => $errorCode,
						'error' => $errorMessage
					));
				}
				else
				{
					craft()->userSession->setError($errorMessage);

					$vars = array(
						'loginName' => $loginName,
						'rememberMe' => $rememberMe
					);
				}
			}
		}

		if (craft()->request->isCpRequest())
		{
			$template = 'login';
		}
		else
		{
			$template = craft()->config->get('loginPath');
		}

		$this->renderTemplate($template, $vars);
	}

	/**
	 *
	 */
	public function actionLogout()
	{
		craft()->userSession->logout(false);
		$this->redirect('');
	}

	/**
	 * Sends a Forgot Password email.
	 */
	public function actionForgotPassword()
	{
		$this->requirePostRequest();

		$loginName = craft()->request->getRequiredPost('loginName');

		$user = craft()->users->getUserByUsernameOrEmail($loginName);

		if ($user)
		{
			if (craft()->users->sendForgotPasswordEmail($user))
			{
				if (craft()->request->isAjaxRequest())
				{
					$this->returnJson(array('success' => true));
				}
				else
				{
					craft()->userSession->setNotice(Craft::t('Check your email for instructions to reset your password.'));
					$this->redirectToPostedUrl();
				}
			}
			else
			{
				$error = Craft::t('There was a problem sending the forgot password email.');
			}
		}
		else
		{
			$error = Craft::t('Invalid username or email.');
		}

		if (craft()->request->isAjaxRequest())
		{
			$this->returnErrorJson($error);
		}
		else
		{
			craft()->userSession->setError($error);
		}
	}

	/**
	 * Sets a user's password once they've verified they have access to their email.
	 *
	 * @throws HttpException
	 * @throws Exception
	 */
	public function actionSetPassword()
	{
		if (craft()->userSession->isLoggedIn())
		{
			$this->redirect('');
		}

		if (craft()->request->isPostRequest())
		{
			$this->requirePostRequest();

			$code = craft()->request->getRequiredPost('code');
			$id = craft()->request->getRequiredPost('id');
			$user = craft()->users->getUserByVerificationCodeAndUid($code, $id);

			if (!$user)
			{
				throw new Exception(Craft::t('Invalid verification code.'));
			}

			$newPassword = craft()->request->getRequiredPost('newPassword');
			$user->newPassword = $newPassword;

			if (craft()->users->changePassword($user))
			{
				// Log them in
				if (!craft()->userSession->login($user->username, $newPassword))
				{
					$errorCode = craft()->userSession->getLoginErrorCode();
					$errorMessage = craft()->userSession->getLoginErrorMessage($errorCode);

					Craft::log('Tried to automatically log in after a password update, but could not: '.$errorMessage, LogLevel::Warning);
				}

				craft()->userSession->setNotice(Craft::t('Password updated.'));
				$this->redirectToPostedUrl();
			}
			else
			{
				craft()->userSession->setNotice(Craft::t('Couldn’t update password.'));

				// Send the data back to the template
				craft()->urlManager->setRouteVariables(array(
					'errors' => $user->getErrors('newPassword'),
					'code'   => $code,
					'id'     => $id
				));
			}
		}
		else
		{
			$code = craft()->request->getQuery('code');
			$id = craft()->request->getQuery('id');
			$user = craft()->users->getUserByVerificationCodeAndUid($code, $id);

			if (!$user)
			{
				throw new HttpException(404);
			}

			$template = craft()->users->getSetPasswordUrl($code, $id, false);

			$this->renderTemplate($template, array(
				'code' => $code,
				'id' => $id,
				'newUser' => ($user->password ? false : true),
			));
		}
	}

	/**
	 * Validate that a user has access to an email address.
	 *
	 * @throws HttpException
	 * @throws Exception
	 */
	public function actionValidate()
	{
		if (craft()->userSession->isLoggedIn())
		{
			$this->redirect('');
		}

		$code = craft()->request->getRequiredQuery('code');
		$id = craft()->request->getRequiredQuery('id');
		$user = craft()->users->getUserByVerificationCodeAndUid($code, $id);

		if (!$user)
		{
			if (($url = craft()->config->get('activateFailurePath')) != '')
			{
				$this->redirect(UrlHelper::getSiteUrl($url));
			}
			else
			{
				throw new Exception(Craft::t('Invalid verification code.'));
			}
		}

		if (craft()->users->activateUser($user))
		{
			// Successfully activated user, do they require a password reset?
			if ($user->passwordResetRequired || !$user->password)
			{
				// Password reset required, generating a new verification code and sending to the setPassword url.
				$code = craft()->users->setVerificationCodeOnUser($user);
				$url = craft()->users->getSetPasswordUrl($code, $id);
			}
			else
			{
				// No password reset required.
				if (($url = craft()->config->get('activateSuccessPath')) != '')
				{
					// They have specified a custom validate success path, use it.
					$url = UrlHelper::getSiteUrl(craft()->config->get('activateSuccessPath'));
				}
				else
				{
					// No password reset required and no custom validate success path.  Send to login page.
					$url = UrlHelper::getUrl(craft()->config->get('loginPath'));
				}
			}
		}
		else
		{
			if (($url = craft()->config->get('activateFailurePath')) === '')
			{
				// Failed to validate user and there is no custom validation failure path.  Throw an exception.
				throw new Exception(Craft::t('There was a problem activating this account.'));
			}
			else
			{
				// Failed to activate user and there is a custom validate failure path set, so use it.
				$url = UrlHelper::getSiteUrl($url);
			}
		}

		$this->redirect($url);
	}

	/**
	 * Registers a new user, or saves an existing user's account settings.
	 */
	public function actionSaveUser()
	{
		$this->requirePostRequest();

		if (Craft::hasPackage(CraftPackage::Users))
		{
			$userId = craft()->request->getPost('userId');

			if ($userId)
			{
				craft()->userSession->requireLogin();
			}
		}
		else
		{
			craft()->userSession->requireLogin();
			$userId = craft()->userSession->getUser()->id;
		}

		$publicRegistration = false;
		$valid = true;

		// Are we editing an existing user?
		if ($userId)
		{
			if ($userId != craft()->userSession->getUser()->id)
			{
				craft()->userSession->requirePermission('editUsers');
			}

			$user = craft()->users->getUserById($userId);

			if (!$user)
			{
				throw new Exception(Craft::t('No user exists with the ID “{id}”.', array('id' => $userId)));
			}
		}
		else
		{
			// Are they already logged in?
			if (craft()->userSession->getUser())
			{
				// Make sure they have permission to register users
				craft()->userSession->requirePermission('registerUsers');
			}
			else
			{
				// Make sure that public registration is allowed
				if (craft()->systemSettings->getSetting('users', 'allowPublicRegistration', false))
				{
					$publicRegistration = true;
				}
				else
				{
					// Sorry pal.
					throw new HttpException(403);
				}
			}

			$user = new UserModel();
		}

		// Can only change usernames/emails/passwords if you are an admin or this is your account.
		if (craft()->userSession->isAdmin() || ($userId && $userId == craft()->userSession->GetUser()->id))
		{
			// Validate stuff.
			$valid = $this->_validateSensitiveFields($userId, $user, $publicRegistration);
		}

		if ($valid)
		{
			$user->firstName       = craft()->request->getPost('firstName');
			$user->lastName        = craft()->request->getPost('lastName');
			$user->preferredLocale = craft()->request->getPost('preferredLocale');

			// If it's a new user, set the verificationRequired bit.
			if (!$user->id)
			{
				$user->verificationRequired = true;
			}

			// Only admins can require users to reset their passwords
			if (craft()->userSession->isAdmin())
			{
				$user->passwordResetRequired = (bool)craft()->request->getPost('passwordResetRequired');
			}

			try
			{
				if (craft()->users->saveUser($user))
				{
					if ($publicRegistration)
					{
						// Assign them to the default user group, if any
						$defaultGroup = craft()->systemSettings->getSetting('users', 'defaultGroup');

						if ($defaultGroup)
						{
							craft()->userGroups->assignUserToGroups($user->id, array($defaultGroup));
						}
					}

					craft()->userSession->setNotice(Craft::t('User saved.'));

					// TODO: Deprecate
					if (isset($_POST['redirect']))
					{
						$_POST['redirect'] = str_replace('{userId}', '{id}', $_POST['redirect']);
					}

					$this->redirectToPostedUrl($user);
				}
				else
				{
					craft()->userSession->setError(Craft::t('Couldn’t save user.'));
				}
			}
			catch (\phpmailerException $e)
			{
				craft()->userSession->setError(Craft::t('Registered user, but couldn’t send activation email. Check your email settings.'));
			}
		}

		// Send the account back to the template
		craft()->urlManager->setRouteVariables(array(
			'account' => $user
		));

	}

	/**
	 * Saves a user's profile.
	 */
	public function actionSaveProfile()
	{
		$this->requirePostRequest();

		$userId = craft()->request->getRequiredPost('userId');

		if ($userId != craft()->userSession->getUser()->id)
		{
			craft()->userSession->requirePermission('editUsers');
		}

		$user = craft()->users->getUserById($userId);

		if (!$user)
		{
			throw new Exception(Craft::t('No user exists with the ID “{id}”.', array('id' => $userId)));
		}

		$fields = craft()->request->getPost('fields', array());
		$user->setContent($fields);

		if (craft()->users->saveProfile($user))
		{
			craft()->userSession->setNotice(Craft::t('Profile saved.'));
			$this->redirectToPostedUrl();
		}
		else
		{
			craft()->userSession->setError(Craft::t('Couldn’t save profile.'));
		}

		// Send the account back to the template
		craft()->urlManager->setRouteVariables(array(
			'account' => $user,
		));
	}

	/**
	 * Upload a user photo.
	 */
	public function actionUploadUserPhoto()
	{
		$this->requireAjaxRequest();
		craft()->userSession->requireLogin();
		$userId = craft()->request->getRequiredQuery('userId');

		if ($userId != craft()->userSession->getUser()->id)
		{
			craft()->userSession->requirePermission('editUsers');
		}

		// Upload the file and drop it in the temporary folder
		$uploader = new \qqFileUploader();

		try
		{
			// Make sure a file was uploaded
			if ($uploader->file && $uploader->file->getSize())
			{
				$user = craft()->users->getUserById($userId);

				$folderPath = craft()->path->getTempUploadsPath().'userphotos/'.$user->username.'/';

				IOHelper::clearFolder($folderPath);

				IOHelper::ensureFolderExists($folderPath);
				$fileName = IOHelper::cleanFilename($uploader->file->getName());

				$uploader->file->save($folderPath.$fileName);

				// Test if we will be able to perform image actions on this image
				if (!craft()->images->setMemoryForImage($folderPath.$fileName))
				{
					IOHelper::deleteFile($folderPath.$fileName);
					$this->returnErrorJson(Craft::t('The uploaded image is too large'));
				}

				craft()->images->cleanImage($folderPath.$fileName);

				$constraint = 500;
				list ($width, $height) = getimagesize($folderPath.$fileName);

				// If the file is in the format badscript.php.gif perhaps.
				if ($width && $height)
				{
					// Never scale up the images, so make the scaling factor always <= 1
					$factor = min($constraint / $width, $constraint / $height, 1);

					$html = craft()->templates->render('_components/tools/cropper_modal',
						array(
							'imageUrl' => UrlHelper::getResourceUrl('userphotos/temp/'.$user->username.'/'.$fileName),
							'width' => round($width * $factor),
							'height' => round($height * $factor),
							'factor' => $factor
						)
					);

					$this->returnJson(array('html' => $html));
				}
			}
		}
		catch (Exception $exception)
		{
			$this->returnErrorJson($exception->getMessage());
		}

		$this->returnErrorJson(Craft::t('There was an error uploading your photo'));
	}

	/**
	 * Crop user photo.
	 */
	public function actionCropUserPhoto()
	{
		$this->requireAjaxRequest();
		craft()->userSession->requireLogin();

		$userId = craft()->request->getRequiredPost('userId');

		if ($userId != craft()->userSession->getUser()->id)
		{
			craft()->userSession->requirePermission('editUsers');
		}

		try
		{
			$x1 = craft()->request->getRequiredPost('x1');
			$x2 = craft()->request->getRequiredPost('x2');
			$y1 = craft()->request->getRequiredPost('y1');
			$y2 = craft()->request->getRequiredPost('y2');
			$source = craft()->request->getRequiredPost('source');

			// Strip off any querystring info, if any.
			if (($qIndex = strpos($source, '?')) !== false)
			{
				$source = substr($source, 0, strpos($source, '?'));
			}

			$user = craft()->users->getUserById($userId);

			// make sure that this is this user's file
			$imagePath = craft()->path->getTempUploadsPath().'userphotos/'.$user->username.'/'.$source;

			if (IOHelper::fileExists($imagePath) && craft()->images->setMemoryForImage($imagePath))
			{
				craft()->users->deleteUserPhoto($user);

				if (craft()->users->cropAndSaveUserPhoto($imagePath, $x1, $x2, $y1, $y2, $user))
				{
					IOHelper::clearFolder(craft()->path->getTempUploadsPath().'userphotos/'.$user->username);

					$html = craft()->templates->render('users/_edit/_userphoto',
						array(
							'account' => $user
						)
					);

					$this->returnJson(array('html' => $html));
				}
			}
			IOHelper::clearFolder(craft()->path->getTempUploadsPath().'userphotos/'.$user->username);
		}
		catch (Exception $exception)
		{
			$this->returnErrorJson($exception->getMessage());
		}

		$this->returnErrorJson(Craft::t('Something went wrong when processing the photo.'));
	}

	/**
	 * Delete all the photos for current user.
	 */
	public function actionDeleteUserPhoto()
	{
		$this->requireAjaxRequest();
		craft()->userSession->requireLogin();
		$userId = craft()->request->getRequiredPost('userId');

		if ($userId != craft()->userSession->getUser()->id)
		{
			craft()->userSession->requirePermission('editUsers');
		}

		$user = craft()->users->getUserById($userId);
		craft()->users->deleteUserPhoto($user);

		$record = UserRecord::model()->findById($user->id);
		$record->photo = null;
		$record->save();

		// Since Model still believes it has an image, we make sure that it does not so anymore when it reaches the template.
		$user->photo = null;

		$html = craft()->templates->render('users/_edit/_userphoto',
			array(
				'account' => $user
			)
		);

		$this->returnJson(array('html' => $html));
	}

	/**
	 * Saves a user's admin settings.
	 */
	public function actionSaveUserGroups()
	{
		$this->requirePostRequest();
		craft()->userSession->requirePermission('administrateUsers');

		$userId = craft()->request->getRequiredPost('userId');
		$groupIds = craft()->request->getPost('groups');

		craft()->userGroups->assignUserToGroups($userId, $groupIds);

		craft()->userSession->setNotice(Craft::t('User groups saved.'));
		$this->redirectToPostedUrl();
	}

	/**
	 * Saves a user's admin settings.
	 */
	public function actionSaveUserPermissions()
	{
		$this->requirePostRequest();
		craft()->userSession->requirePermission('administrateUsers');

		$userId = craft()->request->getRequiredPost('userId');
		$user = craft()->users->getUserById($userId);

		// Only admins can toggle admin settings
		if (craft()->userSession->isAdmin())
		{
			$user->admin = (bool)craft()->request->getPost('admin');
		}

		craft()->users->saveUser($user);

		// Update the user permissions
		if ($user->admin)
		{
			$permissions = array();
		}
		else
		{
			$permissions = craft()->request->getPost('permissions');
		}

		craft()->userPermissions->saveUserPermissions($userId, $permissions);

		craft()->userSession->setNotice(Craft::t('Permissions saved.'));
		$this->redirectToPostedUrl();
	}

	/**
	 * Sends a new activation email to a user.
	 */
	public function actionSendActivationEmail()
	{
		$this->requirePostRequest();
		craft()->userSession->requirePermission('administrateUsers');

		$userId = craft()->request->getRequiredPost('userId');
		$user = craft()->users->getUserById($userId);

		if (!$user)
		{
			$this->_noUserExists($userId);
		}

		craft()->users->sendActivationEmail($user);

		craft()->userSession->setNotice(Craft::t('Activation email sent.'));
		$this->redirectToPostedUrl();
	}

	/**
	 * Unlocks a user, bypassing the cooldown phase.
	 */
	public function actionUnlockUser()
	{
		$this->requirePostRequest();
		craft()->userSession->requirePermission('administrateUsers');

		$userId = craft()->request->getRequiredPost('userId');
		$user = craft()->users->getUserById($userId);

		if (!$user)
		{
			$this->_noUserExists($userId);
		}

		craft()->users->unlockUser($user);

		craft()->userSession->setNotice(Craft::t('User activated.'));
		$this->redirectToPostedUrl();
	}

	/**
	 * Suspends a user.
	 */
	public function actionSuspendUser()
	{
		$this->requirePostRequest();
		craft()->userSession->requirePermission('administrateUsers');

		$userId = craft()->request->getRequiredPost('userId');
		$user = craft()->users->getUserById($userId);

		if (!$user)
		{
			$this->_noUserExists($userId);
		}

		craft()->users->suspendUser($user);

		craft()->userSession->setNotice(Craft::t('User suspended.'));
		$this->redirectToPostedUrl();
	}

	/**
	 * Unsuspends a user.
	 */
	public function actionUnsuspendUser()
	{
		$this->requirePostRequest();
		craft()->userSession->requirePermission('administrateUsers');

		$userId = craft()->request->getRequiredPost('userId');
		$user = craft()->users->getUserById($userId);

		if (!$user)
		{
			$this->_noUserExists($userId);
		}

		craft()->users->unsuspendUser($user);

		craft()->userSession->setNotice(Craft::t('User unsuspended.'));
		$this->redirectToPostedUrl();
	}

	/**
	 * Saves the asset field layout.
	 */
	public function actionSaveFieldLayout()
	{
		$this->requirePostRequest();
		craft()->userSession->requireAdmin();

		// Set the field layout
		$fieldLayout = craft()->fields->assembleLayoutFromPost(false);
		$fieldLayout->type = ElementType::User;
		craft()->fields->deleteLayoutsByType(ElementType::User);

		if (craft()->fields->saveLayout($fieldLayout, false))
		{
			craft()->userSession->setNotice(Craft::t('User fields saved.'));
			$this->redirectToPostedUrl();
		}
		else
		{
			craft()->userSession->setError(Craft::t('Couldn’t save user fields.'));
		}
	}

	/**
	 * Validates a password for a user.
	 *
	 * @return bool
	 */
	public function actionValidatePassword()
	{
		$this->requirePostRequest();
		$this->requireAjaxRequest();

		$userId = craft()->request->getRequiredParam('userId');
		$password = craft()->request->getRequiredParam('currentPassword');

		$user = craft()->users->getUserById($userId);

		if ($user)
		{
			if (craft()->users->validatePassword($user->password, $password))
			{
				$this->returnJson(array('valid' => true));
			}
		}

		$this->returnJson(array('valid' => false));
	}

	/**
	 * Throws a "no user exists" exception
	 *
	 * @access private
	 * @param int $userId
	 * @throws Exception
	 */
	private function _noUserExists($userId)
	{
		throw new Exception(Craft::t('No user exists with the ID “{id}”.', array('id' => $userId)));
	}

	/**
	 * @param $user
	 * @param $currentPassword
	 * @return bool
	 */
	private function _validateCurrentPassword($user, $currentPassword)
	{
		if ($currentPassword)
		{
			if (craft()->users->validatePassword($user->password, $currentPassword))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * @param $userId
	 * @param $user
	 * @param $publicRegistration
	 * @return bool
	 */
	private function _validateSensitiveFields($userId, $user, $publicRegistration)
	{
		$userName = craft()->request->getPost('username');
		$email = craft()->request->getPost('email');

		$userName = $userName === null ? $email : $userName;

		// If this is an existing user and they changed their username, make sure they have validated with their password.
		if ($userId && $userName != $user->username)
		{
			$currentPassword = craft()->request->getPost('currentPassword');

			if ($currentPassword)
			{
				if ($this->_validateCurrentPassword($user, $currentPassword))
				{
					$user->username = $userName;
				}
				else
				{
					craft()->userSession->setError(Craft::t('Incorrect current password.'));
					Craft::log('Tried to change password for userId: '.$user->id.', but the currentPassword does not match what the user supplied.', LogLevel::Warning);
					return false;
				}
			}
			else
			{
				craft()->userSession->setError(Craft::t('You must supply your existing password.'));
				Craft::log('Tried to change the username for userId: '.$user->id.', but the did not supply the existing password.', LogLevel::Warning);
				return false;
			}
		}

		// If this is an existing user and they changed their username, make sure they have validated with their password.
		if ($userId && $email != $user->email)
		{
			$currentPassword = craft()->request->getPost('currentPassword');

			if ($currentPassword)
			{
				if ($this->_validateCurrentPassword($user, $currentPassword))
				{
					$user->email = $email;
				}
				else
				{
					craft()->userSession->setError(Craft::t('Incorrect current password.'));
					Craft::log('Tried to change password for userId: '.$user->id.', but the currentPassword does not match what the user supplied.', LogLevel::Warning);
					return false;
				}
			}
			else
			{
				craft()->userSession->setError(Craft::t('You must supply your existing password.'));
				Craft::log('Tried to change the email for userId: '.$user->id.', but the did not supply the existing password.', LogLevel::Warning);
				return false;
			}
		}

		// If public registration is enabled, make sure it's a new user before we set the password.
		if ($publicRegistration && !$user->id)
		{
			// Force newPassword to be a string so it gets validated.
			$user->newPassword = (string) craft()->request->getPost('password');
		}
		else
		{
			// Only the existing logged-in user can change their password.
			if ($user->isCurrent())
			{
				$newPassword = craft()->request->getPost('newPassword');
				$currentPassword = craft()->request->getPost('currentPassword');

				// Only actually validate/set it if these are not empty
				if ($newPassword && $currentPassword)
				{
					if ($this->_validateCurrentPassword($user, $currentPassword))
					{
						$user->newPassword = (string)$newPassword;
					}
					else
					{
						craft()->userSession->setError(Craft::t('Incorrect current password.'));
						Craft::log('Tried to change password for userId: '.$user->id.', but the currentPassword does not match what the user supplied.', LogLevel::Warning);
						return false;
					}
				}
				else if ($newPassword && !$currentPassword)
				{
					craft()->userSession->setError(Craft::t('You must supply your existing password.'));
					Craft::log('Tried to change password for userId: '.$user->id.', but the did not supply the existing password.', LogLevel::Warning);
					return false;
				}
			}
		}

		return true;
	}
}
