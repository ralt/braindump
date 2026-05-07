<?php

namespace App\Controller;

use App\Entity\Skill;
use App\Entity\User;
use App\Repository\SkillRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SkillController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private SkillRepository $skills,
    ) {}

    #[Route('/skills', name: 'app_skill_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $page = max(1, $request->query->getInt('page', 1));
        $pager = $this->skills->paginatedForUser($user, $page);

        return $this->render('skill/index.html.twig', [
            'skills' => $pager->items,
            'pager' => $pager,
        ]);
    }

    #[Route('/skills/new', name: 'app_skill_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $skill = new Skill();
        $skill->setUser($user);
        $skill->setName('');
        $skill->setInstructions('');

        if ($request->isMethod('POST')) {
            return $this->save($request, $skill, 'skill_new', 'Skill created.');
        }

        return $this->render('skill/form.html.twig', [
            'skill' => $skill,
            'mode' => 'new',
            'csrf_id' => 'skill_new',
            'action' => $this->generateUrl('app_skill_new'),
        ]);
    }

    #[Route('/skills/{id}/edit', name: 'app_skill_edit', methods: ['GET', 'POST'], requirements: ['id' => '[0-9a-f-]{36}'])]
    public function edit(Skill $skill, Request $request): Response
    {
        $this->assertOwner($skill);

        if ($request->isMethod('POST')) {
            return $this->save($request, $skill, 'skill_edit_' . $skill->getId(), 'Skill updated.');
        }

        return $this->render('skill/form.html.twig', [
            'skill' => $skill,
            'mode' => 'edit',
            'csrf_id' => 'skill_edit_' . $skill->getId(),
            'action' => $this->generateUrl('app_skill_edit', ['id' => $skill->getId()]),
        ]);
    }

    #[Route('/skills/{id}/delete', name: 'app_skill_delete', methods: ['POST'], requirements: ['id' => '[0-9a-f-]{36}'])]
    public function delete(Skill $skill, Request $request): RedirectResponse
    {
        $this->assertOwner($skill);

        if (!$this->isCsrfTokenValid('skill_delete_' . $skill->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $this->em->remove($skill);
        $this->em->flush();

        $this->addFlash('success', 'Skill deleted.');
        return $this->redirectToRoute('app_skill_index');
    }

    private function save(Request $request, Skill $skill, string $csrfId, string $successFlash): Response
    {
        if (!$this->isCsrfTokenValid($csrfId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $name = trim($request->request->getString('name'));
        $instructions = trim($request->request->getString('instructions'));

        $errors = [];
        if ($name === '') {
            $errors[] = 'Name is required.';
        } elseif (mb_strlen($name) > 100) {
            $errors[] = 'Name must be 100 characters or fewer.';
        }
        if ($instructions === '') {
            $errors[] = 'Instructions are required.';
        }

        if ($errors !== []) {
            $skill->setName($name);
            $skill->setInstructions($instructions);
            return $this->render('skill/form.html.twig', [
                'skill' => $skill,
                'mode' => $skill->getId() && $this->em->contains($skill) ? 'edit' : 'new',
                'csrf_id' => $csrfId,
                'action' => $request->getRequestUri(),
                'errors' => $errors,
            ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $skill->setName($name);
        $skill->setInstructions($instructions);

        if (!$this->em->contains($skill)) {
            $this->em->persist($skill);
        }

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            return $this->render('skill/form.html.twig', [
                'skill' => $skill,
                'mode' => $skill->getId() && $this->em->contains($skill) ? 'edit' : 'new',
                'csrf_id' => $csrfId,
                'action' => $request->getRequestUri(),
                'errors' => ['You already have a skill with that name.'],
            ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $this->addFlash('success', $successFlash);
        return $this->redirectToRoute('app_skill_index');
    }

    private function assertOwner(Skill $skill): void
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$skill->getUser()->getId()->equals($user->getId())) {
            throw $this->createAccessDeniedException();
        }
    }
}
