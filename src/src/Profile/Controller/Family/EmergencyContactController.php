<?php

declare(strict_types=1);

namespace App\Profile\Controller\Family;

use App\Account\Entity\User;
use App\Profile\Dto\EmergencyContactInput;
use App\Profile\Entity\EmergencyContact;
use App\Profile\Form\EmergencyContactFormType;
use App\Profile\Repository\EmergencyContactRepository;
use App\Profile\Security\ChildActionVoter;
use App\Profile\ValueObject\PhoneNumber;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The family's emergency contacts (FR-061, BR-064).
 *
 * BR-064 says the parent owns all of the family's contact information, and that is why these
 * hang off the parent account rather than off a child: one number, kept in one place, rather
 * than the same grandparent's phone repeated on three sibling rows and drifting apart.
 *
 * `MANAGE_CONTACTS` is the FR-068 gate. It matters here for a reason that is not obvious: a
 * child login must not be able to *edit* the family's emergency numbers, because changing the
 * number a trainer would ring in an emergency is a safety-relevant write, not a preference.
 *
 * Ownership is not an `#[IsGranted]` on a subject here, because the subject is loaded *by owner*:
 * every route resolves the contact through `findOneForParent()`, which filters on the current
 * account, so another family's id is a 404 and never reaches a voter. That is stricter than an
 * object-level check — there is no path on which the wrong row is loaded and then rejected. It is
 * also why the id is an `int` parameter rather than a `#[MapEntity]` argument: the automatic
 * resolver would load the row before anybody asked whose it was.
 */
#[IsGranted(ChildActionVoter::MANAGE_CONTACTS)]
final class EmergencyContactController extends AbstractController
{
    #[Route('/family/contacts/new', name: 'family_contact_new', methods: ['GET', 'POST'])]
    public function create(
        Request $request,
        #[CurrentUser] User $parent,
        EmergencyContactRepository $contacts,
        EntityManagerInterface $entityManager,
        ClockInterface $clock,
    ): Response {
        $form = $this->createForm(EmergencyContactFormType::class, new EmergencyContactInput());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var EmergencyContactInput $input */
            $input = $form->getData();

            $existing = $contacts->findForParent($parent);

            $contact = new EmergencyContact(
                $parent,
                (string) $input->name,
                (string) $input->relationship,
                // Normalized on the way in, so the directory holds one spelling of a number.
                // The DTO's callback has already proved it parses.
                (string) PhoneNumber::tryParse($input->phone)?->value,
                \count($existing),
                $clock->now(),
            );

            $contacts->add($contact);
            $entityManager->flush();

            $this->addFlash('success', \sprintf('%s has been added as an emergency contact.', $contact->getName()));

            return $this->redirectToRoute('family_index');
        }

        return $this->renderForm($form, null);
    }

    #[Route(
        '/family/contacts/{id}/edit',
        name: 'family_contact_edit',
        methods: ['GET', 'POST'],
        requirements: ['id' => Requirement::DIGITS],
    )]
    public function edit(
        Request $request,
        int $id,
        #[CurrentUser] User $parent,
        EmergencyContactRepository $contacts,
        EntityManagerInterface $entityManager,
        ClockInterface $clock,
    ): Response {
        $contact = $this->requireOwnContact($contacts, $id, $parent);

        $form = $this->createForm(EmergencyContactFormType::class, EmergencyContactInput::fromContact($contact));
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var EmergencyContactInput $input */
            $input = $form->getData();

            $contact->update(
                (string) $input->name,
                (string) $input->relationship,
                (string) PhoneNumber::tryParse($input->phone)?->value,
                $contact->getDisplayOrder(),
                $clock->now(),
            );

            $entityManager->flush();

            $this->addFlash('success', \sprintf('%s\'s details have been saved.', $contact->getName()));

            return $this->redirectToRoute('family_index');
        }

        return $this->renderForm($form, $contact);
    }

    /**
     * Removal is a real delete, unlike almost everything else in this epic.
     *
     * An emergency contact is not history: it is a current instruction about who to ring, and a
     * "deleted" one that a future screen could still read is the failure mode worth avoiding. The
     * grandparent named here never had an account and never consented to being kept.
     */
    #[Route(
        '/family/contacts/{id}/delete',
        name: 'family_contact_delete',
        methods: ['POST'],
        requirements: ['id' => Requirement::DIGITS],
    )]
    public function delete(
        Request $request,
        int $id,
        #[CurrentUser] User $parent,
        EmergencyContactRepository $contacts,
        EntityManagerInterface $entityManager,
    ): Response {
        if (!$this->isCsrfTokenValid('submit', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $contact = $this->requireOwnContact($contacts, $id, $parent);
        $name = $contact->getName();

        $contacts->remove($contact);
        $entityManager->flush();

        $this->addFlash('success', \sprintf('%s is no longer an emergency contact.', $name));

        return $this->redirectToRoute('family_index');
    }

    /**
     * One of the signed-in parent's contacts, or a 404.
     *
     * A 404 rather than a 403 deliberately: the row was never loaded, so the response cannot
     * distinguish "belongs to somebody else" from "does not exist" — and being unable to tell
     * those apart is what stops the endpoint being used to count other families' contacts.
     */
    private function requireOwnContact(EmergencyContactRepository $contacts, int $id, User $parent): EmergencyContact
    {
        return $contacts->findOneForParent($id, $parent)
            ?? throw $this->createNotFoundException('No such emergency contact.');
    }

    private function renderForm(FormInterface $form, ?EmergencyContact $contact): Response
    {
        return $this->render('family/contact_form.html.twig', [
            'form' => $form,
            'contact' => $contact,
        ]);
    }
}
