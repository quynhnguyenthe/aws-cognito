<?php

/*
 * This file is part of AWS Cognito Auth solution.
 *
 * (c) EllaiSys <support@ellaisys.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ellaisys\Cognito\Auth;

use Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

use Ellaisys\Cognito\AwsCognitoClient;

use Exception;
use Illuminate\Validation\ValidationException;
use Ellaisys\Cognito\Exceptions\AwsCognitoException;
use Ellaisys\Cognito\Exceptions\NoLocalUserException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Aws\CognitoIdentityProvider\Exception\CognitoIdentityProviderException;


trait AuthenticatesUsers
{

    /**
     * Pulls list of groups attached to a user in Cognito
     *
     * @param string $username
     * @return mixed
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    protected function getAdminListGroupsForUser(string $username)
    {
        $groups = null;

        try {
            $result = app()->make(AwsCognitoClient::class)->adminListGroupsForUser($username);

            if (!empty($result)) {
                $groups = $result['Groups'];

                if ((!empty($groups)) && is_array($groups)) {
                    foreach ($groups as $key => &$value) {
                        unset($value['UserPoolId']);
                        unset($value['RoleArn']);
                    } //Loop ends                    
                } //End if
            } //End if
        } catch(Exception $e) {
            Log::error('AuthenticatesUsers:getAdminListGroupsForUser:Exception');
        } //Try-catch ends

        return $groups;
    } //End if

    
    /**
     * Attempt to log the user into the application.
     *
     * @param  \Illuminate\Support\Collection  $request
     * @param  \string  $guard (optional)
     * @param  \string  $paramUsername (optional)
     * @param  \string  $paramPassword (optional)
     * @param  \bool  $isJsonResponse (optional)
     *
     * @return mixed
     */
    protected function attemptLogin(Collection $request, string $guard='web', string $paramUsername='email', string $paramPassword='password', bool $isJsonResponse=false)
    {
        try {
            //Get key fields
            $keyUsername = 'email';
            $keyPassword = 'password';
            $rememberMe = $request->has('remember')?$request['remember']:false;

            //Generate credentials array
            $credentials = [
                $keyUsername => $request[$paramUsername],
                $keyPassword => $request[$paramPassword]
            ];

            //Authenticate User
            $claim = Auth::guard($guard)->attempt($credentials, $rememberMe);

        } catch (NoLocalUserException $e) {
            Log::error('AuthenticatesUsers:attemptLogin:NoLocalUserException');

            if (config('cognito.add_missing_local_user_sso')) {
                $response = $this->createLocalUser($credentials, $keyPassword);

                if ($response) {
                    return $response;
                } //End if
            } //End if

            return $this->sendFailedLoginResponse($request, $e, $isJsonResponse, $paramUsername);
        } catch (CognitoIdentityProviderException $e) {
            Log::error('AuthenticatesUsers:attemptLogin:CognitoIdentityProviderException');
            return $this->sendFailedCognitoResponse($e, $isJsonResponse, $paramUsername);
        } catch (Exception $e) {
            Log::error('AuthenticatesUsers:attemptLogin:Exception');
            return $this->sendFailedLoginResponse($request, $e, $isJsonResponse, $paramUsername);
        } //Try-catch ends

        return $claim;
    } //Function ends


    /**
     * Create a local user if one does not exist.
     *
     * @param  array  $credentials
     * @return mixed
     */
    protected function createLocalUser($credentials, string $keyPassword='password')
    {
        $userModel = config('cognito.sso_user_model');
        unset($credentials[$keyPassword]);
        $user = $userModel::create($credentials);

        return $user;
    } //Function ends


    /**
     * Handle Failed Cognito Exception
     *
     * @param CognitoIdentityProviderException $exception
     */
    private function sendFailedCognitoResponse(CognitoIdentityProviderException $exception, bool $isJsonResponse=false, string $paramUsername='email')
    {
        throw ValidationException::withMessages([
            $paramUsername => $exception->getAwsErrorMessage(),
        ]);
    } //Function ends


    /**
     * Handle Generic Exception
     *
     * @param  \Collection $request
     * @param  \Exception $exception
     */
    private function sendFailedLoginResponse(Collection $request, Exception $exception=null, bool $isJsonResponse=false, string $paramUsername='email')
    {
        $message = 'FailedLoginResponse';
        if (!empty($exception)) {
            $message = $exception->getMessage();
        } //End if

        if ($isJsonResponse) {
            return  response()->json([
                'error' => 'cognito.validation.auth.failed',
                'message' => $message
            ], 400);
        } else {
            return redirect()
                ->back()
                ->withErrors([
                    $paramUsername => $message,
                ]);
        } //End if

        throw new HttpException(400, $message);
    } //Function ends

} //Trait ends
