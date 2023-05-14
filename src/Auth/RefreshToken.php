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
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

use Ellaisys\Cognito\AwsCognito;
use Ellaisys\Cognito\AwsCognitoClient;
use Ellaisys\Cognito\AwsCognitoClaim;

use Exception;
use Illuminate\Validation\ValidationException;
use Ellaisys\Cognito\Exceptions\AwsCognitoException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Aws\CognitoIdentityProvider\Exception\CognitoIdentityProviderException;

trait RefreshToken
{
    /**
     * The AwsCognito instance.
     *
     * @var \Ellaisys\Cognito\AwsCognito
     */
    protected $cognito;


    /**
     * RespondsMFAChallenge constructor.
     *
     * @param AwsCognito $cognito
     */
    public function __construct(AwsCognito $cognito) {
        $this->cognito = $cognito;
    }


    /**
     * Generate a new token.
     *
     * @param  \Illuminate\Http\Request|Illuminate\Support\Collection  $request
     * @param  string  $paramUsername (optional)
     * @param  string  $paramRefreshToken (optional)
     *
     * @return mixed
     */
    public function refresh(Request $request, string $paramUsername='email', string $paramRefreshToken='refresh_token')
    {
        try {

            if ($request instanceof Request) {
                //Validate request
                $validator = Validator::make($request->all(), $this->rules());

                if ($validator->fails()) {
                    throw new ValidationException($validator);
                } //End if

                $request = collect($request->all());
            } //End if

            //Create AWS Cognito Client
            $client = app()->make(AwsCognitoClient::class);

            //Get Authenticated user
            $authUser  = Auth::guard('api')->user();

            //Get User Data
            $user = $client->getUser($authUser[$paramUsername]);

            //Use username from AWS to refresh token, not email from login!
            if (!empty($user['Username'])) {
                $response = $client->refreshToken($user['Username'], $request[$paramRefreshToken]);
                if (empty($response) || empty($response['AuthenticationResult'])) {
                    throw new HttpException(400);
                } //End if

                //Authenticate User
                $claim = new AwsCognitoClaim($response, $authUser, 'email');
                if ($claim && $claim instanceof AwsCognitoClaim) { 
                    //Store the token
                    $this->cognito->setClaim($claim)->storeToken();

                    //Return the response object
                    return $claim->getData();    
                } else {
                    return false;            
                } //End if
            } else {
                return response()->json(['error' => 'cognito.validation.invalid_username'], 400);
            } //End if
        } catch(Exception $e) {
            if ($e instanceof CognitoIdentityProviderException) {
                return response()->json(['error' => $e->getAwsErrorCode()], 400);
            } //End if
            throw $e;
        } //Try-catch ends
    } //Function ends


    /**
     * Get the password reset validation rules.
     *
     * @return array
     */
    protected function rules()
    {
        return [
            'refresh_token'    => 'required'
        ];
    } //Function ends

} //Trait ends
