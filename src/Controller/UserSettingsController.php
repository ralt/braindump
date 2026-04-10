<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\ApiKeyEncryptor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class UserSettingsController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private ApiKeyEncryptor $encryptor,
    ) {}

    #[Route('/settings', name: 'app_user_settings')]
    public function settings(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($request->isMethod('POST')) {
            $apiKey = $request->request->getString('anthropic_api_key');

            if ($apiKey !== '') {
                $user->setEncryptedAnthropicApiKey($this->encryptor->encrypt($apiKey));
            } else {
                $user->setEncryptedAnthropicApiKey(null);
            }

            $this->em->flush();
            $this->addFlash('success', 'Settings saved.');

            return $this->redirectToRoute('app_user_settings');
        }

        return $this->render('user/settings.html.twig', [
            'hasApiKey' => $user->getEncryptedAnthropicApiKey() !== null,
        ]);
    }
}
