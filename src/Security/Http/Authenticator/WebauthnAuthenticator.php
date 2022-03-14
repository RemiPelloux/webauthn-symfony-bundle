<?php

declare(strict_types=1);

namespace Webauthn\Bundle\Security\Http\Authenticator;

use Assert\Assertion;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bridge\PsrHttpMessage\HttpMessageFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\InteractiveAuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Throwable;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\Bundle\Security\Authentication\Token\WebauthnToken;
use Webauthn\Bundle\Security\Http\Authenticator\Passport\Credentials\WebauthnCredentials;
use Webauthn\Bundle\Security\Storage\OptionsStorage;
use Webauthn\Bundle\Security\WebauthnFirewallConfig;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialLoader;
use Webauthn\PublicKeyCredentialRequestOptions;

final class WebauthnAuthenticator implements AuthenticatorInterface, InteractiveAuthenticatorInterface
{
    private LoggerInterface $logger;

    public function __construct(
        private WebauthnFirewallConfig $firewallConfig,
        private UserProviderInterface $userProvider,
        private AuthenticationSuccessHandlerInterface $successHandler,
        private AuthenticationFailureHandlerInterface $failureHandler,
        private HttpMessageFactoryInterface $httpMessageFactory,
        private OptionsStorage $optionsStorage,
        private array $securedRelyingPartyIds,
        private PublicKeyCredentialLoader $publicKeyCredentialLoader,
        private AuthenticatorAssertionResponseValidator $assertionResponseValidator,
        private AuthenticatorAttestationResponseValidator $attestationResponseValidator,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function supports(Request $request): ?bool
    {
        if ($request->getMethod() !== Request::METHOD_POST) {
            return false;
        }

        if ($this->firewallConfig->isAuthenticationEnabled() && $this->firewallConfig->isAuthenticationResultPathRequest(
            $request
        )) {
            return true;
        }
        if ($this->firewallConfig->isRegistrationEnabled() && $this->firewallConfig->isRegistrationResultPathRequest(
            $request
        )) {
            return true;
        }

        return false;
    }

    public function authenticate(Request $request): Passport
    {
        if ($this->firewallConfig->isAuthenticationResultPathRequest($request)) {
            return $this->processWithAssertion($request);
        }

        return $this->processWithAttestation($request);
    }

    public function createToken(Passport $passport, string $firewallName): TokenInterface
    {
        /** @var WebauthnCredentials $credentialsBadge */
        $credentialsBadge = $passport->getBadge(WebauthnCredentials::class);
        Assertion::isInstanceOf($credentialsBadge, WebauthnCredentials::class, 'Invalid credentials');

        /** @var UserBadge $userBadge */
        $userBadge = $passport->getBadge(UserBadge::class);
        Assertion::isInstanceOf($userBadge, UserBadge::class, 'Invalid user');

        /** @var AuthenticatorAttestationResponse|AuthenticatorAssertionResponse $response */
        $response = $credentialsBadge->getAuthenticatorResponse();
        if ($response instanceof AuthenticatorAssertionResponse) {
            $authData = $response->getAuthenticatorData();
        } else {
            $authData = $response->getAttestationObject()
                ->getAuthData()
            ;
        }

        $token = new  WebauthnToken(
            $credentialsBadge->getPublicKeyCredentialUserEntity(),
            $credentialsBadge->getPublicKeyCredentialOptions(),
            $credentialsBadge->getPublicKeyCredentialSource()
                ->getPublicKeyCredentialDescriptor(),
            $authData->isUserPresent(),
            $authData->isUserVerified(),
            $authData->getReservedForFutureUse1(),
            $authData->getReservedForFutureUse2(),
            $authData->getSignCount(),
            $authData->getExtensions(),
            $credentialsBadge->getFirewallName(),
            $userBadge->getUser()
                ->getRoles()
        );
        $token->setUser($userBadge->getUser());

        return $token;
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $this->logger->info('User has been authenticated successfully with Webauthn.', [
            'identifier' => $token->getUserIdentifier(),
        ]);

        return $this->successHandler->onAuthenticationSuccess($request, $token);
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $this->logger->info('Webauthn authentication request failed.', [
            'exception' => $exception,
        ]);

        return $this->failureHandler->onAuthenticationFailure($request, $exception);
    }

    public function isInteractive(): bool
    {
        return true;
    }

    private function processWithAssertion(Request $request): Passport
    {
        try {
            $content = $request->getContent();
            Assertion::string($content, 'Invalid data');
            $publicKeyCredential = $this->publicKeyCredentialLoader->load($content);
            $response = $publicKeyCredential->getResponse();
            Assertion::isInstanceOf($response, AuthenticatorAssertionResponse::class, 'Invalid response');

            $data = $this->optionsStorage->get();
            $publicKeyCredentialRequestOptions = $data->getPublicKeyCredentialOptions();
            Assertion::isInstanceOf(
                $publicKeyCredentialRequestOptions,
                PublicKeyCredentialRequestOptions::class,
                'Invalid data'
            );

            $userEntity = $data->getPublicKeyCredentialUserEntity();
            $psr7Request = $this->httpMessageFactory->createRequest($request);
            $source = $this->assertionResponseValidator->check(
                $publicKeyCredential->getRawId(),
                $response,
                $publicKeyCredentialRequestOptions,
                $psr7Request,
                $userEntity?->getId(),
                $this->securedRelyingPartyIds
            );

            $credentials = new WebauthnCredentials(
                $response,
                $publicKeyCredentialRequestOptions,
                $userEntity,
                $source,
                $this->firewallConfig->getFirewallName()
            );
            $userBadge = new UserBadge($source->getUserHandle(), [$this->userProvider, 'loadUserByIdentifier']);

            return new Passport($userBadge, $credentials, []);
        } catch (Throwable $e) {
            throw new AuthenticationException($e->getMessage(), $e->getCode(), $e);
        }
    }

    private function processWithAttestation(Request $request): Passport
    {
        try {
            $content = $request->getContent();
            Assertion::string($content, 'Invalid data');
            $publicKeyCredential = $this->publicKeyCredentialLoader->load($content);
            $response = $publicKeyCredential->getResponse();
            Assertion::isInstanceOf($response, AuthenticatorAttestationResponse::class, 'Invalid response');

            $data = $this->optionsStorage->get();
            $publicKeyCredentialCreationOptions = $data->getPublicKeyCredentialOptions();
            Assertion::isInstanceOf(
                $publicKeyCredentialCreationOptions,
                PublicKeyCredentialCreationOptions::class,
                'Invalid data'
            );

            $userEntity = $data->getPublicKeyCredentialUserEntity();
            Assertion::notNull($userEntity, 'Invalid data');
            $psr7Request = $this->httpMessageFactory->createRequest($request);
            $credentialSource = $this->attestationResponseValidator->check(
                $response,
                $publicKeyCredentialCreationOptions,
                $psr7Request,
                $this->securedRelyingPartyIds
            );

            $this->credentialUserEntityRepository->saveUserEntity($userEntity);
            $this->credentialSourceRepository->saveCredentialSource($credentialSource);

            $credentials = new WebauthnCredentials(
                $response,
                $publicKeyCredentialCreationOptions,
                $userEntity,
                $credentialSource,
                $this->firewallConfig->getFirewallName()
            );
            $userBadge = new UserBadge($credentialSource->getUserHandle(), [
                $this->userProvider,
                'loadUserByIdentifier',
            ]);

            return new Passport($userBadge, $credentials, []);
        } catch (Throwable $e) {
            throw new AuthenticationException($e->getMessage(), $e->getCode(), $e);
        }
    }
}
