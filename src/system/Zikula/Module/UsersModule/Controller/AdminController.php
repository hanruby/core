<?php
/**
 * Copyright Zikula Foundation 2009 - Zikula Application Framework
 *
 * This work is contributed to the Zikula Foundation under one or more
 * Contributor Agreements and licensed to You under the following license:
 *
 * @license GNU/LGPLv3 (or at your option, any later version).
 *
 * Please see the NOTICE file distributed with this source code for further
 * information regarding copyright and licensing.
 */

namespace Zikula\Module\UsersModule\Controller;

use Zikula_View;
use UserUtil;
use SecurityUtil;
use ModUtil;
use Zikula\Module\UsersModule\Constant as UsersConstant;
use DataUtil;
use DateUtil;
use System;
use LogUtil;
use DateTimeZone;
use DateTime;
use FileUtil;
use Zikula\Core\Event\GenericEvent;
use Exception;
use Zikula_Session;
use Zikula\Core\Hook\ProcessHook;
use Zikula\Core\Hook\ValidationProviders;
use Zikula\Core\Hook\ValidationHook;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Zikula\Core\Exception\FatalErrorException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route; // used in annotations - do not remove
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method; // used in annotations - do not remove
use Symfony\Component\Routing\RouterInterface;

/**
 * @Route("/admin")
 *
 * Administrator-initiated actions for the Users module.
 */
class AdminController extends \Zikula_AbstractController
{
    /**
     * Post initialise.
     *
     * Run after construction.
     *
     * @return void
     */
    protected function postInitialize()
    {
        // Disable caching by default.
        $this->view->setCaching(Zikula_View::CACHE_DISABLED);
    }

    /**
     * Determines if the user currently logged in has administrative access for the Users module.
     *
     * @return bool True if the current user is logged in and has administrator access for the Users
     *                  module; otherwise false.
     */
    private function currentUserIsAdmin()
    {
        return UserUtil::isLoggedIn() && SecurityUtil::checkPermission('ZikulaUsersModule::', '::', ACCESS_ADMIN);
    }

    /**
     * @Route("")
     *
     * Redirects users to the "view" page.
     *
     * @return RedirectResponse
     */
    public function indexAction()
    {
        // Security check will be done in view()
        return new RedirectResponse($this->get('router')->generate('zikulausersmodule_admin_view', array(), RouterInterface::ABSOLUTE_URL));
    }

    /**
     * @Route("/view")
     * @Method("GET")
     *
     * Shows all items and lists the administration options.
     *
     * @param Request $request
     *
     * Parameters passed via GET:
     * --------------------------
     * numeric startnum The ordinal number at which to start displaying user records.
     * string  letter   The first letter of the user names to display.
     * string  sort     The field on which to sort the data.
     * string  sortdir  Either 'ASC' for an ascending sort (a to z) or 'DESC' for a descending sort (z to a).
     *
     * @return Response symfony response object containing the rendered template.
     *
     * @throws AccessDeniedException Thrown if the current user does not have moderate access, or if the method of accessing this function is improper.
     */
    public function viewAction(Request $request)
    {
        if (!SecurityUtil::checkPermission('ZikulaUsersModule::', '::', ACCESS_MODERATE)) {
            throw new AccessDeniedException();
        }

        // we need this value multiple times, so we keep it
        $itemsPerPage = $this->getVar(UsersConstant::MODVAR_ITEMS_PER_PAGE);

        $letter = $request->query->get('letter', null);
        $sort = $request->query->get('sort', ($letter ? 'uname' : 'uid'));
        $sortDirection = $request->query->get('sortdir', ($letter ? 'ASC' : 'DESC'));
        $sortArgs = array(
            $sort => $sortDirection,
        );
        if (!isset($sortArgs['uname'])) {
            $sortArgs['uname'] = 'ASC';
        }

        $getAllArgs = array(
            'startnum' => $request->query->get('startnum', null) - 1,
            'numitems' => $itemsPerPage,
            'letter' => $letter,
            'sort' => $sortArgs,
        );

        // Get all users as specified by the arguments.
        $userList = ModUtil::apiFunc($this->name, 'user', 'getAll', $getAllArgs);

        // Get all groups
        $groups = ModUtil::apiFunc('ZikulaGroupsModule', 'user', 'getall');

        // check what groups can access the user
        $userGroupsAccess = array();
        $groupsArray = array();
        $canSeeGroups = !empty($groups);

        foreach ($groups as $group) {
            // rewrite the groups array with the group id as key and the group name as value
            $groupsArray[$group['gid']] = array('name' => DataUtil::formatForDisplayHTML($group['name']));
        }

        // Determine the available options
        $currentUserHasModerateAccess = SecurityUtil::checkPermission($this->name . '::', 'ANY', ACCESS_MODERATE);
        $currentUserHasEditAccess = SecurityUtil::checkPermission($this->name . '::', 'ANY', ACCESS_EDIT);
        $currentUserHasDeleteAccess = SecurityUtil::checkPermission($this->name . '::', 'ANY', ACCESS_DELETE);
        $availableOptions = array(
            'lostUsername' => $currentUserHasModerateAccess,
            'lostPassword' => $currentUserHasModerateAccess,
            'toggleForcedPasswordChange' => $currentUserHasEditAccess,
            'modify' => $currentUserHasEditAccess,
            'deleteUsers' => $currentUserHasDeleteAccess,
        );

        $userList = ModUtil::apiFunc('ZikulaUsersModule', 'admin', 'extendUserList', array('userList' => $userList, 'groups' => $groups));

        $pager = array(
            'numitems' => ModUtil::apiFunc($this->name, 'user', 'countItems', array('letter' => $getAllArgs['letter'])),
            'itemsperpage' => $itemsPerPage,
        );

        // Assign the items to the template & return output
        return new Response($this->view->assign('usersitems', $userList)
            ->assign('pager', $pager)
            ->assign('allGroups', $groupsArray)
            ->assign('canSeeGroups', $canSeeGroups)
            ->assign('sort', $sort)
            ->assign('sortdir', $sortDirection)
            ->assign('available_options', $availableOptions)
            ->fetch('Admin/view.tpl'));
    }

    /**
     * @Route("/newuser")
     * @Method({"GET", "POST"})
     *
     * Add a new user to the system.
     *
     * @param Request $request
     *
     * Parameters passed via POST:
     * ---------------------------
     * See the definition of {@link \Zikula\Module\UsersModule\Controller\FormData\NewUserForm}.
     *
     * @return Response symfony response object containing the rendered template.
     *
     * @throws AccessDeniedException Thrown if the current user does not have add access
     * @throws FatalErrorException Thrown if the method of accessing this function is improper
     */
    public function newUserAction(Request $request)
    {
        // The user must have ADD access to submit a new user record.
        if (!SecurityUtil::checkPermission($this->name . '::', '::', ACCESS_ADD)) {
            throw new AccessDeniedException();
        }

        // When new user registration is disabled, the user must have ADMIN access instead of ADD access.
        if (!$this->getVar(UsersConstant::MODVAR_REGISTRATION_ENABLED, false) && !SecurityUtil::checkPermission($this->name . '::', '::', ACCESS_ADMIN)) {
            $registrationUnavailableReason = $this->getVar(UsersConstant::MODVAR_REGISTRATION_DISABLED_REASON, $this->__('Sorry! New user registration is currently disabled.'));
            $request->getSession()->getFlashBag()->add('error', $registrationUnavailableReason);
        }

        $proceedToForm = true;
        $formData = new FormData\NewUserForm('users_newuser', $this->getContainer());
        $errorFields = array();
        $errorMessages = array();

        if ($this->request->getMethod() == 'POST') {
            // Returning from a form POST operation. Process the input.
            $this->checkCsrfToken();

            $formData->setFromRequestCollection($request->request);

            $registrationArgs = array(
                'checkMode' => 'new',
                'emailagain' => $formData->getField('emailagain')->getData(),
                'setpass' => (bool)$formData->getField('setpass')->getData(),
                'antispamanswer' => '',
            );
            $registrationArgs['passagain'] = $registrationArgs['setpass'] ? $formData->getField('passagain')->getData() : '';

            $registrationInfo = array(
                'uname' => $formData->getField('uname')->getData(),
                'pass' => $registrationArgs['setpass'] ? $formData->getField('pass')->getData() : '',
                'passreminder' => $registrationArgs['setpass'] ? $this->__('(Password provided by site administrator)') : '',
                'email' => mb_strtolower($formData->getField('email')->getData()),
            );
            $registrationArgs['reginfo'] = $registrationInfo;

            $sendPass = $formData->getField('sendpass')->getData();

            if ($formData->isValid()) {
                $errorFields = ModUtil::apiFunc($this->name, 'registration', 'getRegistrationErrors', $registrationArgs);
            } else {
                $errorFields = $formData->getErrorMessages();
            }

            $event = new GenericEvent($registrationInfo, array(), new ValidationProviders());
            $this->getDispatcher()->dispatch('module.users.ui.validate_edit.new_user', $event);
            $validators = $event->getData();

            $hook = new ValidationHook($validators);
            $this->dispatchHooks('users.ui_hooks.user.validate_edit', $hook);
            $validators = $hook->getValidators();

            if (empty($errorFields) && !$validators->hasErrors()) {
                // TODO - Future functionality to suppress e-mail notifications, see ticket #2351
                //$currentUserEmail = UserUtil::getVar('email');
                //$adminNotifyEmail = $this->getVar('reg_notifyemail', '');
                //$adminNotification = (strtolower($currentUserEmail) != strtolower($adminNotifyEmail));

                $registeredObj = ModUtil::apiFunc($this->name, 'registration', 'registerNewUser', array(
                    'reginfo' => $registrationInfo,
                    'sendpass' => $sendPass,
                    'usernotification' => $request->request->get('usernotification'),
                    'adminnotification' => $request->request->get('adminnotification')
                ));

                if (isset($registeredObj) && $registeredObj) {
                    $event = new GenericEvent($registeredObj);
                    $this->getDispatcher()->dispatch('module.users.ui.process_edit.new_user', $event);

                    $hook = new ProcessHook($registeredObj['uid']);
                    $this->dispatchHooks('users.ui_hooks.user.process_edit', $hook);

                    if ($registeredObj['activated'] == UsersConstant::ACTIVATED_PENDING_REG) {
                        $request->getSession()->getFlashBag()->add('status', $this->__('Done! Created new registration application.'));
                    } elseif (isset($registeredObj['activated'])) {
                        $request->getSession()->getFlashBag()->add('status', $this->__('Done! Created new user account.'));
                    } else {
                        $request->getSession()->getFlashBag()->add('error', $this->__('Warning! New user information has been saved, however there may have been an issue saving it properly.'));
                    }

                    $proceedToForm = false;
                } else {
                    $request->getSession()->getFlashBag()->add('error', $this->__('Error! Could not create the new user account or registration application.'));
                }
            }
        }

        if ($proceedToForm) {

            return new Response($this->view->assign_by_ref('formData', $formData)
                    ->assign('mode', 'new')
                    ->assign('errorMessages', $errorMessages)
                    ->assign('errorFields', $errorFields)
                    ->fetch('Admin/newuser.tpl'));
        } else {

            return new RedirectResponse($this->get('router')->generate('zikulausersmodule_admin_view', array(), RouterInterface::ABSOLUTE_URL));
        }
    }

    /**
     * Renders a user search form used by both the search operation and the mail users operation.
     *
     * @param string $callbackFunc Either 'search' or 'mailUsers', indicating which operation is calling this function.
     *
     * @return string
     */
    protected function renderSearchForm($callbackFunc = 'search')
    {
        // get group items
        $groups = ModUtil::apiFunc('ZikulaGroupsModule', 'user', 'getAll');

        return $this->view->assign('groups', $groups)
                ->assign('callbackFunc', $callbackFunc)
                ->fetch('Admin/search.tpl');
    }

    /**
     * Gathers the user input from a rendered search form, and also makes the appropriate hook calls.
     *
     * @param Request $request
     * @param string $callbackFunc Either 'search' or 'mailUsers', indicating which operation is calling this function.
     *
     * Parameters pulled from Request $request:
     * ---------------------------
     * string  uname         A fragment of a user name on which to search using an SQL LIKE clause. The user name will be
     *                              surrounded by wildcards.
     * integer ugroup        A group id in which to search (only users who are members of the specified group are returned).
     * string  email         A fragment of an e-mail address on which to search using an SQL LIKE clause. The e-mail address
     *                              will be surrounded by wildcards.
     * string  regdateafter  An SQL date-time (in the form '1970-01-01 00:00:00'); only user accounts with a registration date
     *                              after the date specified will be returned.
     * string  regdatebefore An SQL date-time (in the form '1970-01-01 00:00:00'); only user accounts with a registration date
     *                              before the date specified will be returned.
     * array   dynadata      An array of search values to be passed to the designated profile module. Only those user records
     *                              also satisfying the profile module's search of its dataare returned.
     *
     * @return array|boolean An array of search results, which may be empty; false if the search was unsuccessful.
     */
    protected function getSearchResults(Request $request, $callbackFunc = 'search')
    {
        $findUsersArgs = array(
            'uname'         => $request->request->get('uname', null),
            'email'         => $request->request->get('email', null),
            'ugroup'        => $request->request->get('ugroup', null),
            'regdateafter'  => $request->request->get('regdateafter', null),
            'regdatebefore' => $request->request->get('regdatebefore', null),
        );

        if ($callbackFunc == 'mailUsers') {
              $processEditEvent = $this->getDispatcher()->dispatch('users.mailuserssearch.process_edit', new GenericEvent(null, array(), $findUsersArgs));
        } else {
            $processEditEvent = $this->getDispatcher()->dispatch('users.search.process_edit', new GenericEvent(null, array(), $findUsersArgs));
        }

        $findUsersArgs = $processEditEvent->getData();

        // call the api
        return ModUtil::apiFunc($this->name, 'admin', 'findUsers', $findUsersArgs);
    }

    /**
     * @Route("/search")
     * @Method({"GET", "POST"})
     *
     * Displays a user account search form, or the search results from a post.
     *
     * @param Request $request
     *
     * Parameters passed via GET:
     * --------------------------
     * None.
     *
     * Parameters passed via POST:
     * ---------------------------
     * See the definition of {@link getSearchResults()}.
     *
     * @return Response symfony response object containing the rendered template.
     *
     * @throws AccessDeniedException Thrown if the current user does not have moderate access
     * @throws FatalErrorException if the method of accessing this function is improper.
     * @throws NotFoundHttpException Thrown if no users are found
     */
    public function searchAction(Request $request)
    {
        if (!SecurityUtil::checkPermission($this->name . '::', '::', ACCESS_MODERATE)) {
            throw new AccessDeniedException();
        }

        $actions = array();

        if ($request->isMethod('POST')) {
            $this->checkCsrfToken();

            $usersList = $this->getSearchResults($request);

            if ($usersList) {
                $currentUid = UserUtil::getVar('uid');

                foreach ($usersList as $key => $user) {
                    $actions[$key] = array(
                        'modifyUrl'    => false,
                        'deleteUrl'    => false,
                    );
                    if ($user['uid'] != 1) {
                        if (SecurityUtil::checkPermission($this->name.'::', $user['uname'].'::'.$user['uid'], ACCESS_EDIT)) {
                            $actions[$key]['modifyUrl'] = $this->get('router')->generate('zikulausersmodule_admin_modify', array('userid' => $user['uid']));
                        }
                        if (($currentUid != $user['uid'])
                                && SecurityUtil::checkPermission($this->name.'::', $user['uname'].'::'.$user['uid'], ACCESS_DELETE)) {
                            $actions[$key]['deleteUrl'] = $this->get('router')->generate('zikulausersmodule_admin_deleteusers', array('userid' => $user['uid']));
                        }
                    }
                }
            } else {
                throw new NotFoundHttpException($this->__('Sorry! No matching users found.'));
            }
        }

        if (isset($usersList) && $usersList) {
            $this->view->assign('items', $usersList)
                ->assign('actions', $actions)
                ->assign('deleteUsers', SecurityUtil::checkPermission($this->name . '::', '::', ACCESS_ADMIN));

            return new Response($this->view->fetch('Admin/search_results.tpl'));
        }

        return new Response($this->renderSearchForm('search'));
    }

    /**
     * @Route("/mailusers")
     * @Method({"GET", "POST"})
     * 
     * Search for users and then compose an email to them.
     *
     * @param Request $request
     *
     * Parameters passed via GET:
     * --------------------------
     * None.
     *
     * Parameters passed via POST:
     * ---------------------------
     * string formid The form id posting to this function. Used to determine the workflow.
     *
     * See also the definition of {@link getSearchResults()}.
     *
     * @return Response symfony response object containing the rendered template.
     *
     * @throws FatalErrorException Thrown if the function enters an unknown state or
     *                                     if the method of accessing this function is improper.
     * @throws AccessDeniedException Thrown if the current user does not have comment access
     * @throws NotFoundHttpException Thrown if no users are found
     */
    public function mailUsersAction(Request $request)
    {
        if (!SecurityUtil::checkPermission($this->name . '::MailUsers', '::', ACCESS_COMMENT)) {
            throw new AccessDeniedException();
        }

        $formId = '';
        $userList = false;
        $mailSent = false;

        if ($request->isMethod('POST')) {
            $this->checkCsrfToken();

            $formId = $request->request->get('formid', 'UNKNOWN');

            if ($formId == 'users_search') {
                $userList = $this->getSearchResults($request, 'mailUsers');

                if (!isset($userList) || !$userList) {
                    Throw new NotFoundHttpException($this->__('Sorry! No matching users found.'));
                }
            } elseif ($formId == 'users_mailusers') {
                $uid = $request->request->get('userid', null);
                $sendmail = $request->request->get('sendmail', null);

                $mailSent = ModUtil::apiFunc($this->name, 'admin', 'sendmail', array(
                    'uid'       => $uid,
                    'sendmail'  => $sendmail,
                ));
            } else {
                throw new FatalErrorException($this->__f('An unknown form type was received by %1$s.', array('mailUsers')));
            }
        }

        if ($request->isMethod('GET') || (($formId == 'users_search') && (!isset($userList) || !$userList)) || (($formId == 'users_mailusers') && !$mailSent)) {

            return new Response($this->renderSearchForm('mailUsers'));
        } elseif ($formId == 'users_search') {

            return new Response($this->view->assign('items', $userList)
                ->assign('mailusers', SecurityUtil::checkPermission($this->name . '::MailUsers', '::', ACCESS_COMMENT))
                ->fetch('Admin/mailusers.tpl'));
        } elseif ($formId == 'users_mailusers') {

            return new RedirectResponse($this->get('router')->generate('zikulausersmodule_admin_view', array(), RouterInterface::ABSOLUTE_URL));
        } else {
            throw new FatalErrorException($this->__f('The %1$s function has entered an unknown state.', array('mailUsers')));
        }
    }

    /**
     * @Route("/modify")
     * @Method({"GET", "POST"})
     *
     * Display a form to edit one user account, and process that edit request.
     *
     * @param Request $request
     *
     * Parameters passed via GET:
     * --------------------------
     * numeric userid The user id of the user to be modified.
     * string  uname  The user name of the user to be modified.
     *
     * Parameters passed via POST:
     * ---------------------------
     * array access_permissions An array used to modify a user's group membership.
     *
     * See also the definition of {@link \Zikula\Module\UsersModule\Controller\FormData\ModifyUserForm}.
     *
     * @return Response symfony response object containing the rendered template.
     *
     * @throws AccessDeniedException Thrown if the current user does not have edit access or 
     *                                          if the user id matches the guest account (uid = 1)
     * @throws FatalErrorException Thrown if the method of accessing this function is improper
     * @throws \InvalidArgumentException Thrown if either uid or uname is null
     * @throws NotFoundHttpException Thrown if no such user is found
     */
    public function modifyAction(Request $request)
    {
        // security check for generic edit access
        if (!SecurityUtil::checkPermission('ZikulaUsersModule::', 'ANY', ACCESS_EDIT)) {
            throw new AccessDeniedException();
        }

        $proceedToForm = true;

        $formData = new FormData\ModifyUserForm('users_modify', $this->getContainer());

        if ($request->getMethod() == 'POST') {
            $this->checkCsrfToken();

            $formData->setFromRequestCollection($request->request);
            $accessPermissions = $request->request->get('access_permissions', null);
            $user = $formData->toUserArray(true);
            $originalUser = UserUtil::getVars($user['uid']);
            $userAttributes = isset($originalUser['__ATTRIBUTES__']) ? $originalUser['__ATTRIBUTES__'] : array();

            // security check for this record
            if (!SecurityUtil::checkPermission('ZikulaUsersModule::', "{$originalUser['uname']}::{$originalUser['uid']}", ACCESS_EDIT)) {
                throw new AccessDeniedException();
            }

            if ($formData->isValid()) {
                $registrationArgs = array(
                    'checkmode'         => 'modify',
                    'emailagain'        => $formData->getField('emailagain')->getData(),
                    'setpass'           => (bool)$formData->getField('setpass')->getData(),
                    'antispamanswer'    => '',
                );
                $registrationArgs['passagain'] = $registrationArgs['setpass'] ? $formData->getField('passagain')->getData() : '';

                $registrationArgs['reginfo'] = $user;

                $errorFields = ModUtil::apiFunc($this->name, 'registration', 'getRegistrationErrors', $registrationArgs);
            } else {
                $errorFields = $formData->getErrorMessages();
            }

            $event = new GenericEvent($user, array(), new ValidationProviders());
            $this->getDispatcher()->dispatch('module.users.ui.validate_edit.modify_user', $event);
            $validators = $event->getData();

            $hook = new ValidationHook($validators);
            $this->dispatchHooks('users.ui_hooks.user.validate_edit', $hook);
            $validators = $hook->getValidators();

            if (!$errorFields && !$validators->hasErrors()) {
                if ($originalUser['uname'] != $user['uname']) {
                    // UserUtil::setVar does not allow uname to be changed.
                    // UserUtil::setVar('uname', $user['uname'], $originalUser['uid']);
                    $updatedUserObj = $this->entityManager->find('ZikulaUsersModule:UserEntity', $originalUser['uid']);
                    $updatedUserObj['uname'] = $user['uname'];
                    $this->entityManager->flush();

                    $eventArgs = array(
                        'action'    => 'setVar',
                        'field'     => 'uname',
                        'attribute' => null,
                    );
                    $eventData = array(
                        'old_value' => $originalUser['uname'],
                    );
                    $updateEvent = new GenericEvent($updatedUserObj, $eventArgs, $eventData);
                    $this->getDispatcher()->dispatch('user.account.update', $updateEvent);
                }
                if ($originalUser['email'] != $user['email']) {
                    UserUtil::setVar('email', $user['email'], $originalUser['uid']);
                }
                if ($originalUser['activated'] != $user['activated']) {
                    UserUtil::setVar('activated', $user['activated'], $originalUser['uid']);
                }
                if ($originalUser['theme'] != $user['theme']) {
                    UserUtil::setVar('theme', $user['theme'], $originalUser['uid']);
                }
                if ($formData->getField('setpass')->getData()) {
                    UserUtil::setPassword($user['pass'], $originalUser['uid']);
                    UserUtil::setVar('passreminder', $user['passreminder'], $originalUser['uid']);
                }

                $user = UserUtil::getVars($user['uid'], true);

                // TODO - This all needs to move to a Groups module hook.
                if (isset($accessPermissions)) {
                    // Fixing a high numitems to be sure to get all groups
                    $groups = ModUtil::apiFunc('ZikulaGroupsModule', 'user', 'getAll', array('numitems' => 10000));
                    $curUserGroupMembership = ModUtil::apiFunc('ZikulaGroupsModule', 'user', 'getUserGroups', array('uid' => $user['uid']));

                    foreach ($groups as $group) {
                        if (in_array($group['gid'], $accessPermissions)) {
                            // Check if the user is already in the group
                            $userIsMember = false;
                            if ($curUserGroupMembership) {
                                foreach ($curUserGroupMembership as $alreadyMemberOf) {
                                    if ($group['gid'] == $alreadyMemberOf['gid']) {
                                        $userIsMember = true;
                                        break;
                                    }
                                }
                            }
                            if ($userIsMember == false) {
                                // User is not in this group
                                ModUtil::apiFunc('ZikulaGroupsModule', 'admin', 'addUser', array(
                                    'gid' => $group['gid'],
                                    'uid' => $user['uid']
                                ));
                                $curUserGroupMembership[] = $group;
                            }
                        } else {
                            // We don't need to do a complex check, if the user is not in the group, the SQL will not return
                            // an error anyway.
                            ModUtil::apiFunc('ZikulaGroupsModule', 'admin', 'removeUser', array(
                                'gid' => $group['gid'],
                                'uid' => $user['uid']
                            ));
                        }
                    }
                }

                $event = new GenericEvent($user);
                $this->getDispatcher()->dispatch('module.users.ui.process_edit.modify_user', $event);

                $hook = new ProcessHook($user['uid']);
                $this->dispatchHooks('users.ui_hooks.user.process_edit', $hook);

                $request->getSession()->getFlashBag()->add('status', $this->__("Done! Saved user's account information."));
                $proceedToForm = false;
            }
        } elseif ($request->getMethod() == 'GET') {
            $uid    = $request->query->get('userid', null);
            $uname  = $request->query->get('uname', null);

            // check arguments
            if (is_null($uid) && is_null($uname)) {
                throw new \InvalidArgumentException(LogUtil::getErrorMsgArgs());
            }

            // retrieve userid from uname
            if (is_null($uid) && !empty($uname)) {
                $uid = UserUtil::getIdFromName($uname);
            }

            // warning for guest account
            if ($uid == 1) {
                throw new AccessDeniedException($this->__("Error! You can't edit the guest account."));
            }

            // get the user vars
            $originalUser = UserUtil::getVars($uid);
            if ($originalUser == false) {
                throw new NotFoundHttpException($this->__('Sorry! No such user found.'));
            }
            $userAttributes = isset($originalUser['__ATTRIBUTES__']) ? $originalUser['__ATTRIBUTES__'] : array();

            $formData->setFromArray($originalUser);
            $formData->getField('emailagain')->setData($originalUser['email']);
            $formData->getField('pass')->setData('');

            $accessPermissions = array();
            $errorFields = array();
        }

        if ($proceedToForm) {
            // security check for this record
            if (!SecurityUtil::checkPermission('ZikulaUsersModule::', "{$originalUser['uname']}::{$originalUser['uid']}", ACCESS_EDIT)) {
                throw new AccessDeniedException();
            }

            // groups
            $gidsUserMemberOf = array();
            $allGroups = ModUtil::apiFunc('ZikulaGroupsModule', 'user', 'getall');

            if (!empty($accessPermissions)) {
                $gidsUserMemberOf = $accessPermissions;
                $accessPermissions = array();
            } else {
                $groupsUserMemberOf = ModUtil::apiFunc('ZikulaGroupsModule', 'user', 'getusergroups', array('uid' => $originalUser['uid']));
                foreach ($groupsUserMemberOf as $user_group) {
                    $gidsUserMemberOf[] = $user_group['gid'];
                }
            }

            foreach ($allGroups as $group) {
                if (SecurityUtil::checkPermission('ZikulaGroupsModule::', "{$group['gid']}::", ACCESS_EDIT)) {
                    $accessPermissions[$group['gid']] = array();
                    $accessPermissions[$group['gid']]['name'] = $group['name'];

                    if (in_array($group['gid'], $gidsUserMemberOf) || in_array($group['gid'], $gidsUserMemberOf)) {
                        $accessPermissions[$group['gid']]['access'] = true;
                    } else {
                        $accessPermissions[$group['gid']]['access'] = false;
                    }
                }
            }

            if (!isset($userAttributes['realname'])) {
                $userAttributes['realname'] = '';
            }

            return new Response($this->view->assign_by_ref('formData', $formData)
                ->assign('user_attributes', $userAttributes)
                ->assign('defaultGroupId', ModUtil::getVar('ZikulaGroupsModule', 'defaultgroup', 1))
                ->assign('primaryAdminGroupId', ModUtil::getVar('ZikulaGroupsModule', 'primaryadmingroup', 2))
                ->assign('accessPermissions', $accessPermissions)
                ->assign('errorFields', $errorFields)
                ->assign('hasNoPassword', $originalUser['pass'] == UsersConstant::PWD_NO_USERS_AUTHENTICATION)
                ->fetch('Admin/modify.tpl'));
        } else {

            return new RedirectResponse($this->get('router')->generate('zikulausersmodule_admin_view', array(), RouterInterface::ABSOLUTE_URL));
        }
    }

    /**
     * @Route("/lostusername")
     * @Method({"GET", "POST"})
     * 
     * Allows an administrator to send a user his user name via email.
     *
     * @param Request $request
     *
     * Parameters passed via GET:
     * --------------------------
     * numeric userid The user id of the user to be modified.
     *
     * Parameters passed via POST:
     * ---------------------------
     * numeric userid The user id of the user to be modified.
     *
     * @return RedirectResponse
     *
     * @throws AccessDeniedException Thrown if the current user does not have moderate access.
     * @throws \InvalidArgumentException Thrown if the provided user id isn't an integer
     * @throws NotFoundHttpException Thrown if user id doesn't match a valid user
     *
     * @todo The link on the view page should be a mini form, and should post.
     * @todo This should have a confirmation page.
     */
    public function lostUsernameAction(Request $request)
    {
        if ($request->getMethod() == 'POST') {
            $this->checkCsrfToken();
            $uid = $request->request->get('userid', null);
        } else {
            $this->checkCsrfToken($request->query->get('csrftoken'));
            $uid = $request->query->get('userid', null);
        }

        if (!isset($uid) || !is_numeric($uid) || ((int)$uid != $uid) || ($uid <= 1)) {
            throw new \InvalidArgumentException(LogUtil::getErrorMsgArgs());
        }

        $user = UserUtil::getVars($uid);
        if (!$user) {
            throw new NotFoundHttpException($this->__('Sorry! Unable to retrieve information for that user id.'));
        }

        if (!SecurityUtil::checkPermission('ZikulaUsersModule::', "{$user['uname']}::{$user['uid']}", ACCESS_MODERATE)) {
            throw new AccessDeniedException();
        }

        $userNameSent = ModUtil::apiFunc($this->name, 'user', 'mailUname', array(
            'idfield'       => 'uid',
            'id'            => $user['uid'],
            'adminRequest'  => true,
        ));

        if ($userNameSent) {
            $request->getSession()->getFlashBag()->add('status', $this->__f('Done! The user name for \'%s\' has been sent via e-mail.', $user['uname']));
        } elseif (!$request->getSession()->getFlashBag()->has(Zikula_Session::MESSAGE_ERROR)) {
            $request->getSession()->getFlashBag()->add('error', $this->__f('Sorry! There was an unknown error while trying to send the user name for \'%s\'.', $user['uname']));
        }

        return new RedirectResponse($this->get('router')->generate('zikulausersmodule_admin_view', array(), RouterInterface::ABSOLUTE_URL));
    }

    /**
     * @Route("/lostpassword/{userid}", requirements={"userid" = "^[1-9]\d*$"})
     * @Method("GET")
     *
     * Allows an administrator to send a user a password recovery verification code.
     *
     * @param Request $request
     * @param integer $userid
     *
     * @return RedirectResponse
     *
     * @throws AccessDeniedException Thrown if the current user does not have moderate access.
     * @throws \InvalidArgumentException Thrown if the provided user id isn't an integer or
     *                                          if the user has no password set
     * @throws NotFoundHttpException Thrown if user id doesn't match a valid user
     *
     * @todo The link on the view page should be a mini form, and should post.
     * @todo This should have a confirmation page.
     */
    public function lostPasswordAction(Request $request, $userid)
    {
        $this->checkCsrfToken($request->query->get('csrftoken'));

        $user = UserUtil::getVars($userid);
        if (!$user) {
            throw new NotFoundHttpException($this->__('Sorry! Unable to retrieve information for that user id.'));
        }

        if ($user['pass'] == UsersConstant::PWD_NO_USERS_AUTHENTICATION) {
            // User has no password set -> Sending a recovery code is useless.
            throw new \InvalidArgumentException(LogUtil::getErrorMsgArgs());
        }

        if (!SecurityUtil::checkPermission('ZikulaUsersModule::', "{$user['uname']}::{$user['uid']}", ACCESS_MODERATE)) {
            throw new AccessDeniedException();
        }

        $confirmationCodeSent = ModUtil::apiFunc($this->name, 'user', 'mailConfirmationCode', array(
            'idfield'       => 'uid',
            'id'            => $user['uid'],
            'adminRequest'  => true,
        ));

        if ($confirmationCodeSent) {
            $request->getSession()->getFlashBag()->add('status', $this->__f('Done! The password recovery verification code for %s has been sent via e-mail.', $user['uname']));
        }

        return new RedirectResponse($this->get('router')->generate('zikulausersmodule_admin_view', array(), RouterInterface::ABSOLUTE_URL));
    }

    /**
     * @Route("/deleteusers")
     * @Method({"GET", "POST"})
     * 
     * Display a form to confirm the deletion of one user, and then process the deletion.
     *
     * @param Request $request
     *
     * Parameters passed via GET:
     * --------------------------
     * numeric userid The user id of the user to be deleted.
     * string  uname  The user name of the user to be deleted.
     *
     * Parameters passed via POST:
     * ---------------------------
     * array   userid         The array of user ids of the users to be deleted.
     * boolean process_delete True to process the posted userid list, and delete the corresponding accounts; false or null to confirm first.
     *
     * @return Response|RedirectResponse symfony response object containing the rendered template if a form is to be displayed, RedirectResponse otherwise
     *
     * @throws AccessDeniedException Thrown if the current user does not have delete access
     * @throws FatalErrorException Thrown if the method of accessing this function is improper
     * @throws NotFoundHttpException Thrown if the user doesn't exist
     */
    public function deleteUsersAction(Request $request)
    {
        // check permissions
        if (!SecurityUtil::checkPermission('ZikulaUsersModule::', 'ANY', ACCESS_DELETE)) {
            throw new AccessDeniedException();
        }

        $proceedToForm = false;
        $processDelete = false;

        if ($request->getMethod() == 'POST') {
            $userid = $request->request->get('userid', null);
            $processDelete = $request->request->get('process_delete', false);
            $proceedToForm = !$processDelete;
        } elseif ($request->getMethod() == 'GET') {
            $userid = $request->query->get('userid', null);
            $uname  = $request->query->get('uname', null);

            // retrieve userid from uname
            if (empty($userid) && !empty($uname)) {
                $userid = UserUtil::getIdFromName($uname);
            }

            $proceedToForm = true;
        }

        if (empty($userid)) {
            throw new NotFoundHttpException($this->__('Sorry! No such user found.'));
        }

        if (!is_array($userid)) {
            $userid = array($userid);
        }

        $currentUser = UserUtil::getVar('uid');
        $users = array();
        foreach ($userid as $key => $uid) {
            if ($uid == 1) {
                $request->getSession()->getFlashBag()->add('error', $this->__("Error! You can't delete the guest account."));
                $proceedToForm = false;
                $processDelete = false;
            } elseif ($uid == 2) {
                $request->getSession()->getFlashBag()->add('error', $this->__("Error! You can't delete the primary administrator account."));
                $proceedToForm = false;
                $processDelete = false;
            } elseif ($uid == $currentUser) {
                $request->getSession()->getFlashBag()->add('error', $this->__("Error! You can't delete the account you are currently logged into."));
                $proceedToForm = false;
                $processDelete = false;
            }

            // get the user vars
            $users[$key] = UserUtil::getVars($uid);
            if ($users[$key] == false) {
                throw new NotFoundHttpException($this->__('Sorry! No such user found.'));
            }
        }

        if ($processDelete) {
            $valid = true;
            foreach ($userid as $uid) {
                $event = new GenericEvent(null, array('id' => $uid), new ValidationProviders());
                $validators = $this->getDispatcher()->dispatch('module.users.ui.validate_delete', $event)->getData();

                $hook = new ValidationHook($validators);
                $this->dispatchHooks('users.ui_hooks.user.validate_delete', $hook);
                $validators = $hook->getValidators();

                if ($validators->hasErrors()) {
                    $valid = false;
                }
            }

            $proceedToForm = false;
            if ($valid) {
                $deleted = ModUtil::apiFunc($this->name, 'admin', 'deleteUser', array('uid' => $userid));

                if ($deleted) {
                    foreach ($userid as $uid) {
                        $event = new GenericEvent(null, array('id' => $uid));
                        $this->getDispatcher()->dispatch('module.users.ui.process_delete', $event);

                        $hook = new ProcessHook($uid);
                        $this->dispatchHooks('users.ui_hooks.user.process_delete', $hook);
                    }
                    $count = count($userid);
                    $request->getSession()->getFlashBag()->add('status', $this->_fn('Done! Deleted %1$d user account.', 'Done! Deleted %1$d user accounts.', $count, array($count)));
                }
            }
        }

        if ($proceedToForm) {

            return new Response($this->view->assign('users', $users)
                ->fetch('Admin/deleteusers.tpl'));
        } else {

            return new RedirectResponse($this->get('router')->generate('zikulausersmodule_admin_view', array(), RouterInterface::ABSOLUTE_URL));
        }
    }

    /**
     * Constructs a list of various actions for a list of registrations appropriate for the current user.
     *
     * @param array  $reglist     The list of registration records.
     * @param string $restoreView Indicates where the calling function expects to return to; 'view' indicates
     *                                  that the calling function expects to return to the registration list
     *                                  and 'display' indicates that the calling function expects to return
     *                                  to an individual registration record.
     *
     * @return array An array of valid action URLs for each registration record in the list.
     */
    protected function getActionsForRegistrations(array $reglist, $restoreView = 'view')
    {
        $actions = array();
        if (!empty($reglist)) {
            $approvalOrder = $this->getVar('moderation_order', UsersConstant::APPROVAL_BEFORE);

            // Don't try to put any visual elements here (images, titles, colors, css classes, etc.). Leave that to
            // the template, so that they can be customized without hacking the core code. In fact, all we really need here
            // is what options are enabled. The template could build everything else. We will put the URL for the action
            // in the array for convenience, but that could be done in the template too, really.
            //
            // Make certain that the following goes from most restricted to least (ADMIN...NONE order).  Having the
            // security check as the outer if statement, and similar foreach loops within each saves on repeated checking
            // of permissions, speeding things up a bit.
            if (SecurityUtil::checkPermission('ZikulaUsersModule::', '::', ACCESS_ADMIN)) {
                $actions['count'] = 6;
                foreach ($reglist as $key => $reginfo) {
                    $enableVerify = !$reginfo['isverified'];
                    $enableApprove = !$reginfo['isapproved'];
                    $enableForced = !$reginfo['isverified'] && isset($reginfo['pass']) && !empty($reginfo['pass']);
                    $actions['list'][$reginfo['uid']] = array(
                        'display' => $this->get('router')->generate('zikulausersmodule_admin_displayregistration', array('uid' => $reginfo['uid'])),
                        'modify' => $this->get('router')->generate('zikulausersmodule_admin_modifyregistration', array('uid' => $reginfo['uid'], 'restoreview' => $restoreView)),
                        'verify' => $enableVerify ? $this->get('router')->generate('zikulausersmodule_admin_verifyregistration', array('uid' => $reginfo['uid'], 'restoreview' => $restoreView)) : false,
                        'approve' => $enableApprove ? $this->get('router')->generate('zikulausersmodule_admin_approveregistration', array('uid' => $reginfo['uid'])) : false,
                        'deny' => $this->get('router')->generate('zikulausersmodule_admin_denyregistration', array('uid' => $reginfo['uid'])),
                        'approveForce' => $enableForced ? $this->get('router')->generate('zikulausersmodule_admin_approveregistration', array('uid' => $reginfo['uid'], 'force' => true)) : false,
                    );
                }
            } elseif (SecurityUtil::checkPermission('ZikulaUsersModule::', '::', ACCESS_DELETE)) {
                $actions['count'] = 5;
                foreach ($reglist as $key => $reginfo) {
                    $enableVerify = !$reginfo['isverified'] && (($approvalOrder != UsersConstant::APPROVAL_BEFORE) || $reginfo['isapproved']);
                    $enableApprove = !$reginfo['isapproved'] && (($approvalOrder != UsersConstant::APPROVAL_AFTER) || $reginfo['isverified']);
                    $actions['list'][$reginfo['uid']] = array(
                        'display' => $this->get('router')->generate('zikulausersmodule_admin_displayregistration', array('uid' => $reginfo['uid'])),
                        'modify' => $this->get('router')->generate('zikulausersmodule_admin_modifyregistration', array('uid' => $reginfo['uid'], 'restoreview' => $restoreView)),
                        'verify' => $enableVerify ? $this->get('router')->generate('zikulausersmodule_admin_verifyregistration', array('uid' => $reginfo['uid'], 'restoreview' => $restoreView)) : false,
                        'approve' => $enableApprove ? $this->get('router')->generate('zikulausersmodule_admin_approveregistration', array('uid' => $reginfo['uid'])) : false,
                        'deny' => $this->get('router')->generate('zikulausersmodule_admin_denyregistration', array('uid' => $reginfo['uid'])),
                    );
                }
            } elseif (SecurityUtil::checkPermission('ZikulaUsersModule::', '::', ACCESS_ADD)) {
                $actions['count'] = 4;
                foreach ($reglist as $key => $reginfo) {
                    $actionUrlArgs['uid'] = $reginfo['uid'];
                    $enableVerify = !$reginfo['isverified'] && (($approvalOrder != UsersConstant::APPROVAL_BEFORE) || $reginfo['isapproved']);
                    $enableApprove = !$reginfo['isapproved'] && (($approvalOrder != UsersConstant::APPROVAL_AFTER) || $reginfo['isverified']);
                    $actions['list'][$reginfo['uid']] = array(
                        'display' => $this->get('router')->generate('zikulausersmodule_admin_displayregistration', array('uid' => $reginfo['uid'])),
                        'modify' => $this->get('router')->generate('zikulausersmodule_admin_modifyregistration', array('uid' => $reginfo['uid'], 'restoreview' => $restoreView)),
                        'verify' => $enableVerify ? $this->get('router')->generate('zikulausersmodule_admin_verifyregistration', array('uid' => $reginfo['uid'], 'restoreview' => $restoreView)) : false,
                        'approve' => $enableApprove ? $this->get('router')->generate('zikulausersmodule_admin_approveregistration', array('uid' => $reginfo['uid'])) : false,
                    );
                }
            } elseif (SecurityUtil::checkPermission('ZikulaUsersModule::', '::', ACCESS_EDIT)) {
                $actions['count'] = 3;
                foreach ($reglist as $key => $reginfo) {
                    $actionUrlArgs['uid'] = $reginfo['uid'];
                    $enableVerify = !$reginfo['isverified'] && (($approvalOrder != UsersConstant::APPROVAL_BEFORE) || $reginfo['isapproved']);
                    $actions['list'][$reginfo['uid']] = array(
                        'display' => $this->get('router')->generate('zikulausersmodule_admin_displayregistration', array('uid' => $reginfo['uid'])),
                        'modify' => $this->get('router')->generate('zikulausersmodule_admin_modifyregistration', array('uid' => $reginfo['uid'], 'restoreview' => $restoreView)),
                        'verify' => $enableVerify ? $this->get('router')->generate('zikulausersmodule_admin_verifyregistration', array('uid' => $reginfo['uid'], 'restoreview' => $restoreView)) : false,
                    );
                }
            } elseif (SecurityUtil::checkPermission('ZikulaUsersModule::', '::', ACCESS_MODERATE)) {
                $actions['count'] = 2;
                foreach ($reglist as $key => $reginfo) {
                    $actionUrlArgs['uid'] = $reginfo['uid'];
                    $enableVerify = !$reginfo['isverified'] && (($approvalOrder != UsersConstant::APPROVAL_BEFORE) || $reginfo['isapproved']);
                    $actions['list'][$reginfo['uid']] = array(
                        'display' => $this->get('router')->generate('zikulausersmodule_admin_displayregistration', array('uid' => $reginfo['uid'])),
                        'verify' => $enableVerify ? $this->get('router')->generate('zikulausersmodule_admin_verifyregistration', array('uid' => $reginfo['uid'], 'restoreview' => $restoreView)) : false,
                    );
                }
            }
        }

        return $actions;
    }

    /**
     * @Route("/viewregistrations")
     *
     * Shows all the registration requests (applications), and the options available to the current user.
     *
     * @param Request $request
     *
     * Parameters passed via GET:
     * --------------------------
     * string  restorview If returning from an action, and the previous view should be restored, then the value should be 'view';
     *                          otherwise not present.
     * integer startnum   The ordinal number of the first record to display, especially if using itemsperpage to limit
     *                          the number of records on a single page.
     *
     * Parameters passed via POST:
     * ---------------------------
     * None.
     *
     * Parameters passed via SESSION:
     * ------------------------------
     * Namespace: Zikula_Users
     * Variable:  Users_Controller_Admin_viewRegistrations
     * Type:      array
     * Contents:  An array containing the parameters to restore the view configuration prior to executing an action.
     *
     * @return Response|RedirectResponse symfony response object containing the rendered template if a form is to be display, RedirectResponse otherwise
     *
     * @throws AccessDeniedException Thrown if the current user does not have moderate access.
     */
    public function viewRegistrationsAction(Request $request)
    {
        // security check
        if (!SecurityUtil::checkPermission('ZikulaUsersModule::', '::', ACCESS_MODERATE)) {
            throw new AccessDeniedException();
        }

        $regCount = ModUtil::apiFunc($this->name, 'registration', 'countAll');
        $limitNumRows = $this->getVar(UsersConstant::MODVAR_ITEMS_PER_PAGE, UsersConstant::DEFAULT_ITEMS_PER_PAGE);
        if (!is_numeric($limitNumRows) || ((int)$limitNumRows != $limitNumRows) || (($limitNumRows < 1) && ($limitNumRows != -1))) {
            $limitNumRows = 25;
        }

        $backFromAction = $request->query->get('restoreview', false);

        if ($backFromAction) {
            $returnArgs = $request->getSession()->get('Admin_viewRegistrations', array('startnum' => 1), UsersConstant::SESSION_VAR_NAMESPACE);
            $request->getSession()->remove('Admin_viewRegistrations', UsersConstant::SESSION_VAR_NAMESPACE);

            if ($limitNumRows < 1) {
                unset($returnArgs['startnum']);
            } elseif (!isset($returnArgs['startnum']) || !is_numeric($returnArgs['startnum']) || empty($returnArgs['startnum'])
                    || ((int)$returnArgs['startnum'] != $returnArgs['startnum']) || ($returnArgs['startnum'] < 1)) {

                $returnArgs['startnum'] = 1;
            } elseif ($returnArgs['startnum'] > $regCount) {
                // Probably deleted something. Reset to last page.
                $returnArgs['startnum'] = $regCount - ($regCount % $limitNumRows) + 1;
            } elseif (($returnArgs['startnum'] % $limitNumRows) != 1) {
                // Probably deleted something. Reset to last page.
                $returnArgs['startnum'] = $returnArgs['startnum'] - ($returnArgs['startnum'] % $limitNumRows) + 1;
            }

            // Reset the URL and load the proper page.
            return new RedirectResponse($this->get('router')->generate('zikulausersmodule_admin_viewregistrations', $returnArgs, RouterInterface::ABSOLUTE_URL));
        } else {
            $reset = false;

            $startNum = $request->query->get('startnum', 1);
            if (!is_numeric($startNum) || empty($startNum)  || ((int)$startNum != $startNum) || ($startNum < 1)) {
                $limitOffset = -1;
                $reset = true;
            } elseif ($limitNumRows < 1) {
                $limitOffset = -1;
            } elseif ($startNum > $regCount) {
                // Probably deleted something. Reset to last page.
                $limitOffset = $regCount - ($regCount % $limitNumRows);
                $reset = (($regCount == 0) && ($startNum != 1));
            } elseif (($startNum % $limitNumRows) != 1) {
                // Reset to page boundary
                $limitOffset = $startNum - ($startNum % $limitNumRows) + 1;
                $reset = true;
            } else {
                $limitOffset = $startNum - 1;
            }

            if ($reset) {
                $returnArgs = array();
                if ($limitOffset >= 0) {
                    $returnArgs['startnum'] = $limitOffset + 1;
                }

                return new RedirectResponse($this->get('router')->generate('zikulausersmodule_admin_viewregistrations', $returnArgs, RouterInterface::ABSOLUTE_URL));
            }
        }

        $sessionVars = array(
            'startnum'  => ($limitOffset + 1),
        );
        $request->getSession()->set('Admin_viewRegistrations', $sessionVars, UsersConstant::SESSION_VAR_NAMESPACE);

        $reglist = ModUtil::apiFunc($this->name, 'registration', 'getAll', array('limitoffset' => $limitOffset, 'limitnumrows' => $limitNumRows));

        if (($reglist === false) || !is_array($reglist)) {
            if (!$request->getSession()->getFlashBag()->has(Zikula_Session::MESSAGE_ERROR)) {
                $request->getSession()->getFlashBag()->add('error', $this->__('An error occurred while trying to retrieve the registration records.'));
            }

            return new RedirectResponse($this->get('router')->generate('zikulausersmodule_admin_view', array(), RouterInterface::ABSOLUTE_URL), 500);
        }

        $actions = $this->getActionsForRegistrations($reglist, 'view');

        $pager = array();
        if ($limitNumRows > 0) {
            $pager = array(
                'rowcount'  => $regCount,
                'limit'     => $limitNumRows,
                'posvar'    => 'startnum',
            );
        }

        foreach ($reglist as $key => $user) {
            $reglist[$key]['user_regdate'] = DateUtil::formatDatetime($user['user_regdate'], $this->__('%m-%d-%Y'));
        }

        return new Response($this->view->assign('reglist', $reglist)
                          ->assign('actions', $actions)
                          ->assign('pager', $pager)
                          ->fetch('Admin/viewregistrations.tpl'));
    }

    /**
     * @Route("/displayregistration/{uid}", requirements={"uid" = "^[1-9]\d*$"})
     * @Method("GET")
     *
     * Displays the information on a single registration request.
     *
     * @param Request $request
     * @param integer $uid The id of the registration request (id) to retrieve and display.
     *
     * @return Response symfony response object containing the rendered template.
     *
     * @throws AccessDeniedException Thrown if the current user does not have moderate access
     * @throws FatalErrorException Thrown if the method of accessing this function is improper
     * @throws \InvalidArgumentException Thrown if the user id isn't set or numeric
     */
    public function displayRegistrationAction(Request $request, $uid)
    {
        if (!SecurityUtil::checkPermission('ZikulaUsersModule::', '::', ACCESS_MODERATE)) {
            throw new AccessDeniedException();
        }

        $reginfo = ModUtil::apiFunc($this->name, 'registration', 'get', array('uid' => $uid));
        if (!$reginfo) {
            // get application could fail (return false) because of a nonexistant
            // record, no permission to read an existing record, or a database error
            $request->getSession()->getFlashBag()->add('error', $this->__('Unable to retrieve registration record. '
                . 'The record with the specified id might not exist, or you might not have permission to access that record.'));

            return false;
        }

        // So expiration can be displayed
        $regExpireDays = $this->getVar('reg_expiredays', 0);
        if (!$reginfo['isverified'] && !empty($reginfo['verificationsent']) && ($regExpireDays > 0)) {
            try {
                $expiresUTC = new DateTime($reginfo['verificationsent'], new DateTimeZone('UTC'));
            } catch (Exception $e) {
                $expiresUTC = new DateTime(UsersConstant::EXPIRED, new DateTimeZone('UTC'));
            }
            $expiresUTC->modify("+{$regExpireDays} days");
            $reginfo['validuntil'] = DateUtil::formatDatetime($expiresUTC->format(UsersConstant::DATETIME_FORMAT),
                $this->__('%m-%d-%Y %H:%M'));
        }

        $actions = $this->getActionsForRegistrations(array($reginfo), 'display');

        return new Response($this->view->assign('reginfo', $reginfo)
            ->assign('actions', $actions)
            ->fetch('Admin/displayregistration.tpl'));
    }

    /**
     * @Route("/modifyregistration")
     * @Method({"GET", "POST"})
     *
     * Display a form to edit one registration account.
     *
     * @param Request $request
     *
     * Parameters passed via GET:
     * --------------------------
     * numeric uid        The id of the registration request (id) to retrieve and display.
     * string  restorview To restore the main view to use the filtering options present prior to executing this function, then 'view',
     *                          otherwise not present.
     *
     * Parameters passed via POST:
     * ---------------------------
     * string restorview To restore the main view to use the filtering options present prior to executing this function, then 'view',
     *                          otherwise not present.
     *
     * See also the definition of {@link \Zikula\Module\UsersModule\Controller\FormData\ModifyRegistrationForm}.
     *
     * @return Response symfony response object
     *
     * @throws AccessDeniedException Thrown if the current user does not have edit access
     * @throws FatalErrorException Thrown if the method of accessing this function is improper
     * @throws \InvalidArgumentException Thrown if the user id isn't set or numeric
     */
    public function modifyRegistrationAction(Request $request)
    {
        if (!SecurityUtil::checkPermission('ZikulaUsersModule::', 'ANY', ACCESS_EDIT)) {
            throw new AccessDeniedException();
        }

        $proceedToForm = true;

        $formData = new FormData\ModifyRegistrationForm('users_modifyreg', $this->getContainer());
        $errorFields = array();
        $errorMessages = array();

        if ($request->getMethod() == 'POST') {
            $this->checkCsrfToken();

            $formData->setFromRequestCollection($request->request);

            $restoreView = $request->request->get('restoreview', 'view');

            $registration = $formData->toUserArray(true);
            $originalRegistration = UserUtil::getVars($registration['uid'], false, 'uid', true);
            $userAttributes = isset($originalRegistration['__ATTRIBUTES__']) ? $originalRegistration['__ATTRIBUTES__'] : array();

            // security check for this record
            if (!SecurityUtil::checkPermission('ZikulaUsersModule::', "{$originalRegistration['uname']}::{$originalRegistration['uid']}", ACCESS_EDIT)) {
                throw new AccessDeniedException();
            }

            if ($formData->isValid()) {
                $registrationArgs = array(
                    'reginfo'           => $registration,
                    'checkmode'         => 'modify',
                    'emailagain'        => $formData->getField('emailagain')->getData(),
                    'setpass'           => false,
                    'passagain'         => '',
                    'antispamanswer'    => '',
                );
                $errorFields = ModUtil::apiFunc($this->name, 'registration', 'getRegistrationErrors', $registrationArgs);
            } else {
                $errorFields = $formData->getErrorMessages();
            }

            $event = new GenericEvent($registration, array(), new ValidationProviders());
            $this->getDispatcher()->dispatch('module.users.ui.validate_edit.modify_registration', $event);
            $validators = $event->getData();

            $hook = new ValidationHook($validators);
            $this->dispatchHooks('users.ui_hooks.registration.validate_edit', $hook);
            $validators = $hook->getValidators();

            if (!$errorFields && !$validators->hasErrors()) {
                $emailUpdated = false;
                if ($originalRegistration['uname'] != $registration['uname']) {
                    // UserUtil::setVar does not allow uname to be changed.
                    // UserUtil::setVar('uname', $registration['uname'], $originalRegistration['uid']);
                    $updatedRegistrationObj = $this->entityManager->find('ZikulaUsersModule:UserEntity', $originalRegistration['uid']);
                    $updatedRegistrationObj['uname'] = $registration['uname'];
                    $this->entityManager->flush();

                    $eventArgs = array(
                        'action'    => 'setVar',
                        'field'     => 'uname',
                        'attribute' => null,
                    );
                    $eventData = array(
                        'old_value' => $originalRegistration['uname'],
                    );
                    $updateEvent = new GenericEvent($updatedRegistrationObj, $eventArgs, $eventData);
                    $this->getDispatcher()->dispatch('user.registration.update', $updateEvent);
                }
                if ($originalRegistration['theme'] != $registration['theme']) {
                    UserUtil::setVar('theme', $registration['theme'], $originalRegistration['uid']);
                }
                if ($originalRegistration['email'] != $registration['email']) {
                    UserUtil::setVar('email', $registration['email'], $originalRegistration['uid']);
                    $emailUpdated = true;
                }

                $registration = UserUtil::getVars($registration['uid'], true, 'uid', true);

                if ($emailUpdated) {
                    $approvalOrder = $this->getVar('moderation_order', UsersConstant::APPROVAL_BEFORE);
                    if (!$originalRegistration['isverified'] && (($approvalOrder != UsersConstant::APPROVAL_BEFORE) || $originalRegistration['isapproved'])) {
                        $verificationSent = ModUtil::apiFunc($this->name, 'registration', 'sendVerificationCode', array(
                            'reginfo'   => $registration,
                            'force'     => true,
                        ));
                    }
                }

                $event = new GenericEvent($registration);
                $this->getDispatcher()->dispatch('module.users.ui.process_edit.modify_registration', $event);

                $hook = new ProcessHook($registration['uid']);
                $this->dispatchHooks('users.ui_hooks.registration.process_edit', $hook);

                $request->getSession()->getFlashBag()->add('status', $this->__("Done! Saved user's account information."));
                $proceedToForm = false;
            }

        } elseif ($request->getMethod() == 'GET') {
            $uid = $request->query->get('uid', null);

            if (!is_int($uid)) {
                if (!is_numeric($uid) || ((string)((int)$uid) != $uid)) {
                    throw new \InvalidArgumentException($this->__('Error! Invalid registration uid.'));
                }
            }

            $registration = ModUtil::apiFunc($this->name, 'registration', 'get', array('uid' => $uid));

            if (!$registration) {
                throw new NotFoundHttpException($this->__('Error! Unable to load registration record.'));
            }
            $userAttributes = isset($registration['__ATTRIBUTES__']) ? $registration['__ATTRIBUTES__'] : array();

            $formData->setFromArray($registration);
            $formData->getField('emailagain')->setData($registration['email']);

            $restoreView = $request->query->get('restoreview', 'view');
        }

        if ($proceedToForm) {
            // security check for this record
            if (!SecurityUtil::checkPermission('ZikulaUsersModule::', "{$registration['uname']}::{$registration['uid']}", ACCESS_EDIT)) {
                throw new AccessDeniedException();
            }

            $rendererArgs = array(
                'user_attributes'       => $userAttributes,
                'errorMessages'         => $errorMessages,
                'errorFields'           => $errorFields,
                'restoreview'           => $restoreView,
            );

            // Return the output that has been generated by this function
            return new Response($this->view->assign_by_ref('formData', $formData)
                ->assign($rendererArgs)
                ->fetch('Admin/modifyregistration.tpl'));
        } else {
            if ($restoreView == 'view') {

                return new RedirectResponse($this->get('router')->generate('zikulausersmodule_admin_viewregistrations', array('restoreview' => true), RouterInterface::ABSOLUTE_URL));
            } else {

                return new RedirectResponse($this->get('router')->generate('zikulausersmodule_admin_displayregistration', array('uid' => $registration['uid']), RouterInterface::ABSOLUTE_URL));
            }
        }
    }

    /**
     * @Route("/verifyregistration")
     * @Method({"GET", "POST"})
     *
     * Renders and processes the admin's force-verify form.
     *
     * Renders and processes a form confirming an administrators desire to skip verification for
     * a registration record, approve it and add it to the users table.
     *
     * @param Request $request
     *
     * Parameters passed via GET:
     * --------------------------
     * numeric uid        The id of the registration request (id) to verify.
     * boolean force      True to force the registration to be verified.
     * string  restorview To restore the main view to use the filtering options present prior to executing this function, then 'view',
     *                          otherwise not present.
     *
     * Parameters passed via POST:
     * ---------------------------
     * numeric uid        The id of the registration request (uid) to verify.
     * boolean force      True to force the registration to be verified.
     * boolean confirmed  True to execute this function's action.
     * string  restorview To restore the main view to use the filtering options present prior to executing this function, then 'view',
     *                          otherwise not present.
     *
     * @return Response symfony response object
     *
     * @throws AccessDeniedException Thrown if the current user does not have moderate access
     * @throws FatalErrorException Thrown if the method of accessing this function is improper
     * @throws \InvalidArgumentException Thrown if the user id isn't set or numeric
     * @throws NotFoundHttpException Thrown if the registration record couldn't be retrieved
     */
    public function verifyRegistrationAction(Request $request)
    {
        if (!SecurityUtil::checkPermission('ZikulaUsersModule::', '::', ACCESS_MODERATE)) {
            throw new AccessDeniedException();
        }

        if ($request->getMethod() == 'GET') {
            $uid = $request->query->get('uid', null);
            $forceVerification = $this->currentUserIsAdmin() && $request->query->get('force', false);
            $restoreView = $request->query->get('restoreview', 'view');
            $confirmed = false;
        } elseif ($request->getMethod() == 'POST') {
            $this->checkCsrfToken();
            $uid = $request->request->get('uid', null);
            $forceVerification = $this->currentUserIsAdmin() && $request->request->get('force', false);
            $restoreView = $request->request->get('restoreview', 'view');
            $confirmed = $request->request->get('confirmed', false);
        }

        if (!isset($uid) || !is_numeric($uid) || ((int)$uid != $uid)) {
            throw new \InvalidArgumentException(LogUtil::getErrorMsgArgs());
        }

        // Got just a uid.
        $reginfo = ModUtil::apiFunc($this->name, 'registration', 'get', array('uid' => $uid));
        if (!$reginfo) {
            throw new NotFoundHttpException($this->__f('Error! Unable to retrieve registration record with uid \'%1$s\'', $uid));
        }

        if ($restoreView == 'display') {
            $cancelUrl = $this->get('router')->generate('zikulausersmodule_admin_displayregistration', array('uid' => $reginfo['uid']), RouterInterface::ABSOLUTE_URL);
        } else {
            $cancelUrl = $this->get('router')->generate('zikulausersmodule_admin_viewregistrations', array('restoreview' => true), RouterInterface::ABSOLUTE_URL);
        }

        $approvalOrder = $this->getVar('moderation_order', UsersConstant::APPROVAL_BEFORE);

        if ($reginfo['isverified']) {
            $request->getSession()->getFlashBag()->add('error', $this->__f('Error! A verification code cannot be sent for the registration record for \'%1$s\'. It is already verified.', $reginfo['uname']));
        } elseif (!$forceVerification && ($approvalOrder == UsersConstant::APPROVAL_BEFORE) && !$reginfo['isapproved']) {
            $request->getSession()->getFlashBag()->add('error', $this->__f('Error! A verification code cannot be sent for the registration record for \'%1$s\'. It must first be approved.', $reginfo['uname']));
        }

        if (!$confirmed) {
            // So expiration can be displayed
            $regExpireDays = $this->getVar('reg_expiredays', 0);
            if (!$reginfo['isverified'] && $reginfo['verificationsent'] && ($regExpireDays > 0)) {
                try {
                    $expiresUTC = new DateTime($reginfo['verificationsent'], new DateTimeZone('UTC'));
                } catch (Exception $e) {
                    $expiresUTC = new DateTime(UsersConstant::EXPIRED, new DateTimeZone('UTC'));
                }
                $expiresUTC->modify("+{$regExpireDays} days");
                $reginfo['validuntil'] = DateUtil::formatDatetime($expiresUTC->format(UsersConstant::DATETIME_FORMAT),
                    $this->__('%m-%d-%Y %H:%M'));
            }

            return new Response($this->view->assign('reginfo', $reginfo)
                              ->assign('restoreview', $restoreView)
                              ->assign('force', $forceVerification)
                              ->assign('cancelurl', $cancelUrl)
                              ->fetch('Admin/verifyregistration.tpl'));

        } else {
            $verificationSent = ModUtil::apiFunc($this->name, 'registration', 'sendVerificationCode', array(
                'reginfo'   => $reginfo,
                'force'     => $forceVerification,
            ));

            if (!$verificationSent) {
                $request->getSession()->getFlashBag()->add('error', $this->__f('Sorry! There was a problem sending a verification code to \'%1$s\'.', $reginfo['uname']));
            } else {
                $request->getSession()->getFlashBag()->add('status', $this->__f('Done! Verification code sent to \'%1$s\'.', $reginfo['uname']));
            }

            return new RedirectResponse($cancelUrl);
        }
    }

    /**
     * @Route("/approveregistration")
     * @Method({"GET", "POST"})
     *
     * Renders and processes a form confirming an administrators desire to approve a registration.
     *
     * @param Request $request
     *
     * If the registration record is also verified (or verification is not needed) a users table
     * record is created.
     *
     * Parameters passed via GET:
     * --------------------------
     * numeric uid        The id of the registration request (id) to approve.
     * boolean force      True to force the registration to be approved.
     * string  restorview To restore the main view to use the filtering options present prior to executing this function, then 'view',
     *                          otherwise not present.
     *
     * Parameters passed via POST:
     * ---------------------------
     * numeric uid        The id of the registration request (uid) to approve.
     * boolean force      True to force the registration to be approved.
     * string  restorview To restore the main view to use the filtering options present prior to executing this function, then 'view',
     *                          otherwise not present.
     *
     * @return Response Symfony response object
     *
     * @throws AccessDeniedException Thrown if the current user does not have moderate access
     * @throws FatalErrorException Thrown if the method of accessing this function is improper
     * @throws \InvalidArgumentException Thrown if the user id isn't set or numeric
     * @throws NotFoundHttpException Thrown if the registration record couldn't be retrieved
     */
    public function approveRegistrationAction(Request $request)
    {
        if (!SecurityUtil::checkPermission('ZikulaUsersModule::', '::', ACCESS_MODERATE)) {
            throw new AccessDeniedException();
        }

        if ($request->getMethod() == 'GET') {
            $uid = $request->query->get('uid', null);
            $forceVerification = $this->currentUserIsAdmin() && $request->query->get('force', false);
            $restoreView = $request->query->get('restoreview', 'view');
        } elseif ($request->getMethod() == 'POST') {
            $uid = $request->request->get('uid', null);
            $forceVerification = $this->currentUserIsAdmin() && $request->request->get('force', false);
            $restoreView = $request->request->get('restoreview', 'view');
        }

        if (!isset($uid) || !is_numeric($uid) || ((int)$uid != $uid)) {
            throw new \InvalidArgumentException(LogUtil::getErrorMsgArgs());
        }

        // Got just an id.
        $reginfo = ModUtil::apiFunc($this->name, 'registration', 'get', array('uid' => $uid));
        if (!$reginfo) {
            throw new NotFoundHttpException($this->__f('Error! Unable to retrieve registration record with uid \'%1$s\'', $uid));

        }

        if ($restoreView == 'display') {
            $cancelUrl = $this->get('router')->generate('zikulausersmodule_admin_displayregistration', array('uid' => $reginfo['uid']), RouterInterface::ABSOLUTE_URL);
        } else {
            $cancelUrl = $this->get('router')->generate('zikulausersmodule_admin_viewregistrations', array('restoreview' => true), RouterInterface::ABSOLUTE_URL);
        }

        $approvalOrder = $this->getVar('moderation_order', UsersConstant::APPROVAL_BEFORE);

        if ($reginfo['isapproved'] && !$forceVerification) {
            $request->getSession()->getFlashBag()->add('error', $this->__f('Warning! Nothing to do! The registration record with uid \'%1$s\' is already approved.', $reginfo['uid']));
        } elseif (!$forceVerification && ($approvalOrder == UsersConstant::APPROVAL_AFTER) && !$reginfo['isapproved']
                && !SecurityUtil::checkPermission('ZikulaUsersModule::', '::', ACCESS_ADMIN)) {
            $request->getSession()->getFlashBag()->add('error', $this->__f('Error! The registration record with uid \'%1$s\' cannot be approved. The registration\'s e-mail address must first be verified.', $reginfo['uid']));
        } elseif ($forceVerification && (!isset($reginfo['pass']) || empty($reginfo['pass']))) {
            $request->getSession()->getFlashBag()->add('error', $this->__f('Error! E-mail verification cannot be skipped for \'%1$s\'. The user must establish a password as part of the verification process.', $reginfo['uname']));
        }


        $confirmed = $request->get('confirmed', false);
        if (!$confirmed) {
            // Bad or no auth key, or bad or no confirmation, so display confirmation.

            // So expiration can be displayed
            $regExpireDays = $this->getVar('reg_expiredays', 0);
            if (!$reginfo['isverified'] && !empty($reginfo['verificationsent']) && ($regExpireDays > 0)) {
                try {
                    $expiresUTC = new DateTime($reginfo['verificationsent'], new DateTimeZone('UTC'));
                } catch (Exception $e) {
                    $expiresUTC = new DateTime(UsersConstant::EXPIRED, new DateTimeZone('UTC'));
                }
                $expiresUTC->modify("+{$regExpireDays} days");
                $reginfo['validuntil'] = DateUtil::formatDatetime($expiresUTC->format(UsersConstant::DATETIME_FORMAT),
                    $this->__('%m-%d-%Y %H:%M'));
            }

            return new Response($this->view->assign('reginfo', $reginfo)
                              ->assign('restoreview', $restoreView)
                              ->assign('force', $forceVerification)
                              ->assign('cancelurl', $cancelUrl)
                              ->fetch('Admin/approveregistration.tpl'));

        } else {
            $this->checkCsrfToken();

            $approved = ModUtil::apiFunc($this->name, 'registration', 'approve', array(
                'reginfo'   => $reginfo,
                'force'     => $forceVerification,
            ));

            if (!$approved) {
                $request->getSession()->getFlashBag()->add('error', $this->__f('Sorry! There was a problem approving the registration for \'%1$s\'.', $reginfo['uname']));
            } else {
                if (isset($approved['uid'])) {
                    $request->getSession()->getFlashBag()->add('status', $this->__f('Done! The registration for \'%1$s\' has been approved and a new user account has been created.', $reginfo['uname']));

                    return new RedirectResponse($cancelUrl);
                } else {
                    $request->getSession()->getFlashBag()->add('status', $this->__f('Done! The registration for \'%1$s\' has been approved and is awaiting e-mail verification.', $reginfo['uname']));

                    return new RedirectResponse($cancelUrl);
                }
            }
        }
    }

    /**
     * @Route("/denyregistration")
     * @Method({"GET", "POST"})
     *
     * Render and process a form confirming the administrator's rejection of a registration.
     *
     * @param Request $request
     *
     * If the denial is confirmed, the registration is deleted from the database.
     *
     * Parameters passed via GET:
     * --------------------------
     * numeric uid        The id of the registration request (id) to deny.
     * string  restorview To restore the main view to use the filtering options present prior to executing this function, then 'view',
     *                          otherwise not present.
     *
     * Parameters passed via POST:
     * ---------------------------
     * numeric uid        The id of the registration request (uid) to deny.
     * boolean confirmed  True to execute this function's action.
     * boolean usernorify True to notify the user that his registration request was denied; otherwise false.
     * string  reason     The reason the registration request was denied, included in the notification.
     * string  restorview To restore the main view to use the filtering options present prior to executing this function, then 'view',
     *                          otherwise not present.
     *
     * @return Response Symfony response object
     *
     * @throws AccessDeniedException Thrown if the current user does not have delete access
     * @throws FatalErrorException Thrown if the method of accessing this function is improper
     * @throws \InvalidArgumentException Thrown if the user id isn't set or numeric
     * @throws NotFoundHttpException Thrown if the registration record couldn't be retrieved
     */
    public function denyRegistrationAction(Request $request)
    {
        if (!SecurityUtil::checkPermission('ZikulaUsersModule::', '::', ACCESS_DELETE)) {
            throw new AccessDeniedException();
        }

        if ($request->getMethod() == 'GET') {
            $uid = $request->query->get('uid', null);
            $restoreView = $request->query->get('restoreview', 'view');
            $confirmed = false;
        } elseif ($request->getMethod() == 'POST') {
            $this->checkCsrfToken();
            $uid = $request->request->get('uid', null);
            $restoreView = $request->request->get('restoreview', 'view');
            $sendNotification = $request->request->get('usernotify', false);
            $reason = $request->request->get('reason', '');
            $confirmed = $request->request->get('confirmed', false);
        } else {
            throw new FatalErrorException();
        }

        if (!isset($uid) || !is_numeric($uid) || ((int)$uid != $uid)) {
            throw new \InvalidArgumentException(LogUtil::getErrorMsgArgs());
        }

        // Got just a uid.
        $reginfo = ModUtil::apiFunc($this->name, 'registration', 'get', array('uid' => $uid));
        if (!$reginfo) {
            throw new NotFoundHttpException($this->__f('Error! Unable to retrieve registration record with uid \'%1$s\'', $uid));
        }

        if ($restoreView == 'display') {
            $cancelUrl = $this->get('router')->generate('zikulausersmodule_admin_displayregistration', array('uid' => $reginfo['uid']), RouterInterface::ABSOLUTE_URL);
        } else {
            $cancelUrl = $this->get('router')->generate('zikulausersmodule_admin_viewregistrations', array('restoreview' => true), RouterInterface::ABSOLUTE_URL);
        }

        if (!$confirmed) {
            // Bad or no auth key, or bad or no confirmation, so display confirmation.

            // So expiration can be displayed
            $regExpireDays = $this->getVar('reg_expiredays', 0);
            if (!$reginfo['isverified'] && !empty($reginfo['verificationsent']) && ($regExpireDays > 0)) {
                try {
                    $expiresUTC = new DateTime($reginfo['verificationsent'], new DateTimeZone('UTC'));
                } catch (Exception $e) {
                    $expiresUTC = new DateTime(UsersConstant::EXPIRED, new DateTimeZone('UTC'));
                }
                $expiresUTC->modify("+{$regExpireDays} days");
                $reginfo['validuntil'] = DateUtil::formatDatetime($expiresUTC->format(UsersConstant::DATETIME_FORMAT),
                    $this->__('%m-%d-%Y %H:%M'));
            }

            return new Response($this->view->assign('reginfo', $reginfo)
                              ->assign('restoreview', $restoreView)
                              ->assign('cancelurl', $cancelUrl)
                              ->fetch('Admin/denyregistration.tpl'));

        } else {
            $denied = ModUtil::apiFunc($this->name, 'registration', 'remove', array(
                'reginfo'   => $reginfo,
            ));

            if (!$denied) {
                $request->getSession()->getFlashBag()->add('error', $this->__f('Sorry! There was a problem deleting the registration for \'%1$s\'.', $reginfo['uname']));
            } else {
                if ($sendNotification) {
                    $siteurl   = System::getBaseUrl();
                    $rendererArgs = array(
                        'sitename'  => System::getVar('sitename'),
                        'siteurl'   => substr($siteurl, 0, strlen($siteurl)-1),
                        'reginfo'   => $reginfo,
                        'reason'    => $reason,
                    );

                    $sent = ModUtil::apiFunc($this->name, 'user', 'sendNotification', array(
                        'toAddress'         => $reginfo['email'],
                        'notificationType'  => 'regdeny',
                        'templateArgs'      => $rendererArgs
                    ));
                }
                $request->getSession()->getFlashBag()->add('status', $this->__f('Done! The registration for \'%1$s\' has been denied and deleted.', $reginfo['uname']));

                return new RedirectResponse($cancelUrl);
            }
        }
    }

    /**
     * @Route("/config")
     * @Method({"GET", "POST"})
     *
     * Edit and update module configuration settings.
     *
     * @param Request $request
     *
     * Parameters passed via POST:
     * ---------------------------
     * See the definition of {@link \Zikula\Module\UsersModule\Controller\FormData\ConfigForm}.
     *
     * @return Response symfony response object
     *
     * @throws FatalErrorException Thrown if the function is accessed improperly.
     * @throws AccessDeniedException Thrown if the current user does not have admin access.
     */
    public function configAction(Request $request)
    {
        // Security check
        if (!(SecurityUtil::checkPermission($this->name . '::', '::', ACCESS_ADMIN))) {
            throw new AccessDeniedException();
        }

        $configData = new FormData\ConfigForm('users_config', $this->getContainer());
        $errorFields = array();

        if ($request->getMethod() == 'POST') {
            $this->checkCsrfToken();

            $configData->setFromRequestCollection($request->request);

            if ($configData->isValid()) {
                $modVars = $configData->toArray();
                $this->setVars($modVars);
                $request->getSession()->getFlashBag()->add('status', $this->__('Done! Users module settings have been saved.'));
                $event = new GenericEvent(null, array(), $modVars);
                $this->getDispatcher()->dispatch('module.users.config.updated', $event);
            } else {
                $errorFields = $configData->getErrorMessages();
                $errorCount = count($errorFields);
                $request->getSession()->getFlashBag()->add('error', $this->_fn('There was a problem with one of the module settings. Please review the message below, correct the error, and resubmit your changes.',
                    'There were problems with %1$d module settings. Please review the messages below, correct the errors, and resubmit your changes.',
                    $errorCount, array($errorCount)));
            }
        }

        return new Response($this->view->assign_by_ref('configData', $configData)
            ->assign('errorFields', $errorFields)
            ->fetch('Admin/config.tpl'));
    }

    /**
     * @Route("/import")
     * @Method({"GET", "POST"})
     *
     * Show the form to choose a CSV file and import several users from this file.
     *
     * @param Request $request
     *
     * Parameters passed via GET:
     * --------------------------
     * None.
     *
     * Parameters passed via POST:
     * ---------------------------
     * boolean confirmed  True if the user has confirmed the upload/import.
     * array   importFile Structured information about the file to import, from <input type="file" name="fileFieldName" ... /> and stored
     *                          in $_FILES['fileFieldName']. See http://php.net/manual/en/features.file-upload.post-method.php .
     * integer delimiter  A code indicating the type of delimiter found in the import file. 1 = comma, 2 = semicolon, 3 = colon.
     *
     * Parameters passed via SESSION:
     * ------------------------------
     * None.
     *
     * @return Response symfony response object
     *
     * @throws AccessDeniedException Thrown if the current user does not have add access.
     */
    public function importAction(Request $request)
    {
        // security check
        if (!SecurityUtil::checkPermission('ZikulaUsersModule::', '::', ACCESS_ADD)) {
            throw new AccessDeniedException();
        }

        // get input values. Check for direct function call first because calling function might be either get or post
        if ($request->getMethod() == 'GET') {
            $confirmed = false;
        } elseif ($request->getMethod() == 'POST') {
            $this->checkCsrfToken();
            $confirmed = $request->request->get('confirmed', false);
        }

        // set default parameters
        $minpass = $this->getVar('minpass');
        $defaultGroup = ModUtil::getVar('ZikulaGroupsModule', 'defaultgroup');

        if ($confirmed) {
            // get other import values
            $importFile = $request->files->get('importFile', null);
            $delimiter = $request->request->get('delimiter', null);
            $importResults = $this->uploadImport($importFile, $delimiter);
            if ($importResults == '') {
                // the users have been imported successfully
                $request->getSession()->getFlashBag()->add('status', $this->__('Done! Users imported successfully.'));

                return new RedirectResponse($this->get('router')->generate('zikulausersmodule_admin_view', array(), RouterInterface::ABSOLUTE_URL));
            }
        }

        // shows the form
        $post_max_size = ini_get('post_max_size');
        // get default group
        $group = ModUtil::apiFunc('ZikulaGroupsModule','user','get', array('gid' => $defaultGroup));
        $defaultGroup = $defaultGroup . ' (' . $group['name'] . ')';

        return new Response($this->view->assign('importResults', isset($importResults) ? $importResults : '')
                ->assign('post_max_size', $post_max_size)
                ->assign('minpass', $minpass)
                ->assign('defaultGroup', $defaultGroup)
                ->fetch('Admin/import.tpl'));
    }

    /**
     * @Route("/export")
     * @Method({"GET", "POST"})
     *
     * Show the form to export a CSV file of users.
     *
     * @param Request $request
     *
     * Parameters passed via GET:
     * --------------------------
     * None.
     *
     * Parameters passed via POST:
     * ---------------------------
     * boolean confirmed       True if the user has confirmed the export.
     * string  exportFile      Filename of the file to export (optional) (default=users.csv)
     * integer delimiter       A code indicating the type of delimiter found in the export file. 1 = comma, 2 = semicolon, 3 = colon, 4 = tab.
     * integer exportEmail     Flag to export email addresses, 1 for yes.
     * integer exportTitles    Flag to export a title row, 1 for yes.
     * integer exportLastLogin Flag to export the last login date/time, 1 for yes.
     * integer exportRegDate   Flag to export the registration date/time, 1 for yes.
     * integer exportGroups    Flag to export the group membership, 1 for yes.
     *
     * Parameters passed via SESSION:
     * ------------------------------
     * None.
     *
     * @return Response
     *
     * @throws \InvalidArgumentException Thrown if parameters are passed via the $args array, but $args is invalid.
     * @throws AccessDeniedException Thrown if the current user does not have admin access
     * @throws FatalErrorException Thrown if the method of accessing this function is improper
     */
    public function exporterAction(Request $request)
    {
        // security check
        if (!SecurityUtil::checkPermission('ZikulaUsersModule::', '::', ACCESS_ADMIN)) {
            throw new AccessDeniedException();
        }

        if ($request->getMethod() == 'GET') {
            $confirmed = false;
        } elseif ($request->getMethod() == 'POST') {
            $this->checkCsrfToken();
            $confirmed = $request->request->get('confirmed', false);
            $exportFile = $request->request->get('exportFile', null);
            $delimiter = $request->request->get('delimiter', null);
            $email = $request->request->get('exportEmail', null);
            $titles = $request->request->get('exportTitles', null);
            $lastLogin = $request->request->get('exportLastLogin', null);
            $regDate = $request->request->get('exportRegDate', null);
            $groups = $request->request->get('exportGroups', null);
        }

        if ($confirmed) {
            // get other import values
            $email = (!isset($email) || $email !=='1') ? false : true;
            $titles = (!isset($titles) || $titles !== '1') ? false : true;
            $lastLogin = (!isset($lastLogin) || $lastLogin !=='1') ? false : true;
            $regDate = (!isset($regDate) || $regDate !== '1') ? false : true;
            $groups = (!isset($groups) || $groups !== '1') ? false : true;

            if (!isset($delimiter) || $delimiter == '') {
                $delimiter = 1;
            }
            switch ($delimiter) {
                case 1:
                    $delimiter = ",";
                    break;
                case 2:
                    $delimiter = ";";
                    break;
                case 3:
                    $delimiter = ":";
                    break;
                case 4:
                    $delimiter = chr(9);
            }
            if (!isset($exportFile) || $exportFile == '') {
                $exportFile = 'users.csv';
            }
            if (!strrpos($exportFile, '.csv')) {
                $exportFile .= '.csv';
            }

            $colnames = array();

            //get all user fields
            if (ModUtil::available('ProfileModule')) {
                $userfields = ModUtil::apiFunc('ProfileModule', 'user', 'getallactive');

                foreach ($userfields as $item) {
                    $colnames[] = $item['prop_attribute_name'];
                }
            }

            // title fields
            if ($titles == 1) {
                $titlerow = array('id', 'uname');

                //titles for optional data
                if ($email == 1) {
                    array_push($titlerow, 'email');
                }
                if ($regDate == 1) {
                    array_push($titlerow, 'user_regdate');
                }
                if ($lastLogin == 1) {
                    array_push($titlerow, 'lastlogin');
                }
                if ($groups == 1) {
                    array_push($titlerow, 'groups');
                }

                array_merge($titlerow, $colnames);
            } else {
                $titlerow = array();
            }

            //get all users
            $users = ModUtil::apiFunc($this->name, 'user', 'getAll');

            // get all groups
            $allgroups = ModUtil::apiFunc('ZikulaGroupsModule', 'user', 'getall');
            $groupnames = array();
            foreach ($allgroups as $groupitem) {
                $groupnames[$groupitem['gid']] = $groupitem['name'];
            }

            // data for csv
            $datarows = array();

            //loop every user gettin user id and username and all user fields and push onto result array.
            foreach ($users as $user) {
                $uservars = UserUtil::getVars($user['uid']);

                $result = array();

                array_push($result, $uservars['uid'], $uservars['uname']);

                //checks for optional data
                if ($email == 1) {
                    array_push($result, $uservars['email']);
                }
                if ($regDate == 1) {
                    array_push($result, $uservars['user_regdate']);
                }
                if ($lastLogin == 1) {
                    array_push($result, $uservars['lastlogin']);
                }

                if ($groups == 1) {
                    $usergroups = ModUtil::apiFunc('ZikulaGroupsModule', 'user', 'getusergroups',
                                            array('uid'   => $uservars['uid'],
                                                  'clean' => true));

                    $groupstring = "";

                    foreach ($usergroups as $group) {
                        $groupstring .= $groupnames[$group] . chr(124);
                    }

                    $groupstring = rtrim($groupstring, chr(124));


                    array_push($result, $groupstring);
                }

                foreach ($colnames as $colname) {
                    array_push($result, $uservars['__ATTRIBUTES__'][$colname]);
                }

                array_push($datarows, $result);
            }

            //export the csv file
            FileUtil::exportCSV($datarows, $titlerow, $delimiter, '"', $exportFile);
        }

        if (SecurityUtil::checkPermission('ZikulaGroupsModule::', '::', ACCESS_READ)) {
            $this->view->assign('groups', '1');
        }

        return new Response($this->view->fetch('Admin/export.tpl'));
    }

    /**
     * Import several users from a CSV file. Checks needed values and format.
     *
     * Parameters passed via GET:
     * --------------------------
     * None.
     *
     * Parameters passed via POST:
     * ---------------------------
     * None.
     *
     * Parameters passed via SESSION:
     * ------------------------------
     * None.
     *
     * @param array $importFile Information about the file to import. Used as the default
     *                            if $_FILES['importFile'] is not set. Allows this function to be called internally,
     *                            rather than as a result of a form post.
     * @param integer $delimiter A code indicating the delimiter used in the file. Used as the
     *                            default if $_POST['delimiter'] is not set. Allows this function to be called internally,
     *                            rather than as a result of a form post.
     *
     * @return String an empty message if success or an error message otherwise
     */
    protected function uploadImport(array $importFile, $delimiter)
    {
        // get needed values
        $is_admin = (SecurityUtil::checkPermission('ZikulaUsersModule::', '::', ACCESS_ADMIN)) ? true : false;
        $minpass = $this->getVar('minpass');
        $defaultGroup = ModUtil::getVar('ZikulaGroupsModule', 'defaultgroup'); // Create output object;
        // calcs $pregcondition needed to verify illegal usernames
        $reg_illegalusername = $this->getVar('reg_Illegalusername');
        $pregcondition = '';
        if (!empty($reg_illegalusername)) {
            $usernames = explode(" ", $reg_illegalusername);
            $count = count($usernames);
            $pregcondition = "/((";
            for ($i = 0; $i < $count; $i++) {
                if ($i != $count-1) {
                    $pregcondition .= $usernames[$i] . ")|(";
                } else {
                    $pregcondition .= $usernames[$i] . "))/iAD";
                }
            }
        }

        // get available groups
        $allGroups = ModUtil::apiFunc('ZikulaGroupsModule', 'user', 'getall');

        // create an array with the groups identities where the user can add other users
        $allGroupsArray = array();
        foreach ($allGroups as $group) {
            if (SecurityUtil::checkPermission('ZikulaGroupsModule::', $group['gid'] . '::', ACCESS_EDIT)) {
                $allGroupsArray[] = $group['gid'];
            }
        }

        // check if the user's email must be unique
        $reg_uniemail = $this->getVar('reg_uniemail');

        // get the CSV delimiter
        switch ($delimiter) {
            case 1:
                $delimiterChar = ",";
                break;
            case 2:
                $delimiterChar = ";";
                break;
            case 3:
                $delimiterChar = ":";
                break;
        }

        // check that the user have selected a file
        $fileName = $importFile['name'];
        if ($fileName == '') {

            return $this->__("Error! You have not chosen any file.");
        }

        // check if user have selected a correct file
        if (FileUtil::getExtension($fileName) != 'csv') {

            return $this->__("Error! The file extension is incorrect. The only allowed extension is csv.");
        }

        // read the choosen file
        if (!$lines = file($importFile['tmp_name'])) {

            return $this->__("Error! It has not been possible to read the import file.");
        }
        $expectedFields = array('uname', 'pass', 'email', 'activated', 'sendmail', 'groups');
        $counter = 0;
        $importValues = array();
        $usersArray = array();
        $emailsArray = array();

        // read the lines and create an array with the values. Check if the values passed are correct and set the default values if it is necessary
        foreach ($lines as $line_num => $line) {
            $line = str_replace('"', '', trim($line));
            if ($counter == 0) {
                // check the fields defined in the first row
                $firstLineArray = explode($delimiterChar, $line);
                foreach ($firstLineArray as $field) {
                    if (!in_array(trim(strtolower($field)), $expectedFields)) {

                        return $this->__f("Error! The import file does not have the expected field %s in the first row. Please check your import file.", array($field));
                    }
                }
                $counter++;
                continue;
            }
            // get and check the second and following lines
            $lineArray = array();
            $lineArray = DataUtil::formatForOS(explode($delimiterChar, $line));

            // check if the line have all the needed values
            if (count($lineArray) != count($firstLineArray)) {

                return $this->__f('Error! The number of parameters in line %s is not correct. Please check your import file.', $counter);
            }
            $importValues[] = array_combine($firstLineArray, $lineArray);

            // check all the obtained values
            // check user name
            $uname = trim($importValues[$counter - 1]['uname']);
            if ($uname == '' || strlen($uname) > 25) {

                return $this->__f('Sorry! The user name is not valid in line %s. The user name is mandatory and the maximum length is 25 characters. Please check your import file.',
                    $counter);
            }

            // check if it is a valid user name
            // admins are allowed to add any usernames, even those defined as being illegal
            if (!$is_admin && $pregcondition != '') {
                // check for illegal usernames
                if (preg_match($pregcondition, $uname)) {

                    return $this->__f('Sorry! The user name %1$s is reserved and cannot be registered in line %2$s. Please check your import file.', array($uname, $counter));
                }
            }

            // check if the user name is valid because spaces or invalid characters
            if (preg_match("/[[:space:]]/", $uname) || !System::varValidate($uname, 'uname')) {

                return $this->__f('Sorry! The user name %1$s cannot contain spaces in line %2$s. Please check your import file.', array($uname, $counter));
            }

            // check if the user name is repeated
            if (in_array($uname, $usersArray)) {

                return $this->__f('Sorry! The user name %1$s is repeated in line %2$s, and it cannot be used twice for creating accounts. Please check your import file.',
                    array($uname, $counter));
            }
            $usersArray[] = $uname;

            // check password
            $pass = (string)trim($importValues[$counter - 1]['pass']);
            if ($pass == '') {

                return $this->__f('Sorry! You did not provide a password in line %s. Please check your import file.', $counter);
            }

            // check password length
            if (strlen($pass) <  $minpass) {

                return $this->__f('Sorry! The password must be at least %1$s characters long in line %2$s. Please check your import file.', array($minpass, $counter));
            }

            // check email
            $email = trim($importValues[$counter - 1]['email']);
            if ($email == '') {

                return $this->__f('Sorry! You did not provide a email in line %s. Please check your import file.', $counter);
            }

            // check email format
            if (!System::varValidate($email, 'email')) {

                return $this->__f('Sorry! The e-mail address you entered was incorrectly formatted or is unacceptable for other reasons in line %s. Please check your import file.', $counter);
            }

            // check if email is unique only if it is necessary
            if ($reg_uniemail == 1) {
                if (in_array($email, $emailsArray)) {

                    return $this->__f('Sorry! The %1$s e-mail address is repeated in line %2$s, and it cannot be used twice for creating accounts. Please check your import file.',
                        array($email, $counter));
                }
                $emailsArray[] = $email;
            }

            // validate activation value
            $importValues[$counter - 1]['activated'] = isset($importValues[$counter - 1]['activated']) ? (int)$importValues[$counter - 1]['activated'] : UsersConstant::ACTIVATED_ACTIVE;
            $activated = $importValues[$counter - 1]['activated'];
            if (($activated != UsersConstant::ACTIVATED_INACTIVE) && ($activated != UsersConstant::ACTIVATED_ACTIVE)) {

                return $this->__('Error! The CSV is not valid: the "activated" column must contain 0 or 1 only.');
            }

            // validate sendmail
            $importValues[$counter - 1]['sendmail'] = isset($importValues[$counter - 1]['sendmail']) ? (int)$importValues[$counter - 1]['sendmail'] : 0;
            if ($importValues[$counter - 1]['sendmail'] < 0 || $importValues[$counter - 1]['sendmail'] > 1) {

                return $this->__('Error! The CSV is not valid: the "sendmail" column must contain 0 or 1 only.');
            }

            // check groups and set defaultGroup as default if there are not groups defined
            $importValues[$counter - 1]['groups'] = isset($importValues[$counter - 1]['groups']) ? (int)$importValues[$counter - 1]['groups'] : '';
            $groups = $importValues[$counter - 1]['groups'];
            if ($groups == '') {
                $importValues[$counter - 1]['groups'] = $defaultGroup;
            } else {
                $groupsArray = explode('|', $groups);
                foreach ($groupsArray as $group) {
                    if (!in_array($group, $allGroupsArray)) {

                        return $this->__('Sorry! The identity of the group %1$s is not not valid in line %2$s. Perhaps it do not exist. Please check your import file.', array($group, $counter));
                    }
                }
            }
            $counter++;
        }

        // seams that the import file is formated correctly and its values are valid
        if (empty($importValues)) {

            return $this->__("Error! The import file does not have values.");
        }

        // check if users exists in database
        $usersInDB = ModUtil::apiFunc($this->name, 'admin', 'checkMultipleExistence',
                                      array('valuesarray' => $usersArray,
                                            'key' => 'uname'));
        if ($usersInDB === false) {

            return $this->__("Error! Trying to read the existing user names in database.");
        } else {
            if (count($usersInDB) > 0) {

                return $this->__("Sorry! One or more user names really exist in database. The user names must be uniques.");
            }
        }

        // check if emails exists in data base in case the email have to be unique
        if ($reg_uniemail == 1) {
            $emailsInDB = ModUtil::apiFunc($this->name, 'admin', 'checkMultipleExistence',
                                          array('valuesarray' => $emailsArray,
                                                'key' => 'email'));
            if ($emailsInDB === false) {

                return $this->__("Error! Trying to read the existing users' email addressess in database.");
            } else {
                if (count($emailsInDB) > 0) {

                    return $this->__("Sorry! One or more users' email addresses exist in the database. Each user's e-mail address must be unique.");
                }
            }
        }

        // seems that the values in import file are ready. Procceed creating users
        if (!ModUtil::apiFunc($this->name, 'admin', 'createImport', array('importvalues' => $importValues))) {

            return $this->__("Error! The creation of users has failed.");
        }

        return '';
    }

    /**
     * @Route("/forcepasswordchange")
     * @Method({"GET", "POST"})
     *
     * Sets or resets a user's need to changed his password on his next attempt at logging in.
     *
     * @param Request $request
     *
     * Parameters passed via GET:
     * --------------------------
     * numeric userid The uid of the user for whom a change of password should be forced (or canceled).
     *
     * Parameters passed via POST:
     * ---------------------------
     * numeric userid                    The uid of the user for whom a change of password should be forced (or canceled).
     * boolean user_must_change_password True to force the user to change his password at his next log-in attempt, otherwise false.
     *
     * @return Response symfony response object
     *
     * @throws \InvalidArgumentException Thrown if a user id is not specified, is invalid, or does not point to a valid account record,
     *                                      or the account record is not in a consistent state.
     * @throws AccessDeniedException Thrown if the current user does not have edit access for the account record.
     * @throws FatalErrorException Thrown if the method of accessing this function is improper
     */
    public function toggleForcedPasswordChangeAction(Request $request)
    {
        if ($request->getMethod() == 'GET') {
            $uid = $request->query->get('userid', false);

            if (!$uid || !is_numeric($uid) || ((int)$uid != $uid)) {
                throw new \InvalidArgumentException(LogUtil::getErrorMsgArgs());
            }

            $userObj = UserUtil::getVars($uid);

            if (!isset($userObj) || !$userObj || !is_array($userObj) || empty($userObj) || $userObj['pass'] == UsersConstant::PWD_NO_USERS_AUTHENTICATION) {
                throw new \InvalidArgumentException(LogUtil::getErrorMsgArgs());
            }

            if (!SecurityUtil::checkPermission('ZikulaUsersModule::', "{$userObj['uname']}::{$uid}", ACCESS_EDIT)) {
                throw new AccessDeniedException();
            }

            $userMustChangePassword = UserUtil::getVar('_Users_mustChangePassword', $uid, false);

            return new Response($this->view->assign('user_obj', $userObj)
                ->assign('user_must_change_password', $userMustChangePassword)
                ->fetch('Admin/toggleforcedpasswordchange.tpl'));
        } elseif ($request->getMethod() == 'POST') {
            $this->checkCsrfToken();

            $uid = $request->request->get('userid', false);
            $userMustChangePassword = $request->request->get('user_must_change_password', false);

            // Force reload of User object into cache.
            $userObj = UserUtil::getVars($uid);

            if (!$uid || !is_numeric($uid) || ((int)$uid != $uid) || $userObj['pass'] == UsersConstant::PWD_NO_USERS_AUTHENTICATION) {
                throw new \InvalidArgumentException(LogUtil::getErrorMsgArgs());
            }

            if (!SecurityUtil::checkPermission('ZikulaUsersModule::', "{$userObj['uname']}::{$uid}", ACCESS_EDIT)) {
                throw new AccessDeniedException();
            }

            if ($userMustChangePassword) {
                UserUtil::setVar('_Users_mustChangePassword', $userMustChangePassword, $uid);
            } else {
                UserUtil::delVar('_Users_mustChangePassword', $uid);
            }

            // Force reload of User object into cache.
            $userObj = UserUtil::getVars($uid, true);

            if ($userMustChangePassword) {
                if (isset($userObj['__ATTRIBUTES__']) && isset($userObj['__ATTRIBUTES__']['_Users_mustChangePassword'])) {
                    $request->getSession()->getFlashBag()->add('status', $this->__f('Done! A password change will be required the next time %1$s logs in.', array($userObj['uname'])));
                } else {
                    throw new \InvalidArgumentException();
                }
            } else {
                if (isset($userObj['__ATTRIBUTES__']) && isset($userObj['__ATTRIBUTES__']['_Users_mustChangePassword'])) {
                    throw new \InvalidArgumentException();
                } else {
                    $request->getSession()->getFlashBag()->add('status', $this->__f('Done! A password change will no longer be required for %1$s.', array($userObj['uname'])));
                }
            }

            return new RedirectResponse($this->get('router')->generate('zikulausersmodule_admin_view', array(), RouterInterface::ABSOLUTE_URL));
        }
    }
}
