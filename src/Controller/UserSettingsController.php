<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\ApiKeyEncryptorInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

class UserSettingsController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private ApiKeyEncryptorInterface $encryptor,
        private UserPasswordHasherInterface $passwordHasher,
    ) {}

    #[Route('/settings', name: 'app_user_settings')]
    public function settings(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('user_settings', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $apiKey = $request->request->getString('ai_api_key');
            $provider = $request->request->getString('ai_provider');

            $currentPassword = $request->request->getString('current_password');
            $newPassword = $request->request->getString('new_password');

            if ($newPassword !== '') {
                if ($currentPassword === '' || !$this->passwordHasher->isPasswordValid($user, $currentPassword)) {
                    $this->addFlash('error', 'Current password is incorrect.');
                    return $this->redirectToRoute('app_user_settings');
                }

                if (strlen($newPassword) < 8) {
                    $this->addFlash('error', 'New password must be at least 8 characters.');
                    return $this->redirectToRoute('app_user_settings');
                }

                $user->setPassword($this->passwordHasher->hashPassword($user, $newPassword));
            }

            if ($apiKey !== '') {
                $user->setEncryptedAiApiKey($this->encryptor->encrypt($apiKey));
            } else {
                $user->setEncryptedAiApiKey(null);
            }

            if ($provider !== '') {
                $user->setAiProvider($provider);
            }

            $this->em->flush();
            $this->addFlash('success', 'Settings saved.');

            return $this->redirectToRoute('app_user_settings');
        }

        return $this->render('user/settings.html.twig', [
            'hasApiKey' => $user->getEncryptedAiApiKey() !== null,
            'aiProvider' => $user->getAiProvider() ?? 'anthropic',
        ]);
    }
}
