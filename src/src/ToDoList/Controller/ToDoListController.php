<?php

namespace App\ToDoList\Controller;

use App\ToDoList\Entity\Task;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/to/do', name: 'to_do_')]
final class ToDoListController extends AbstractController
{
    #[Route('/list', name: 'list')]
    public function index(EntityManagerInterface $entityManager): Response
    {
        $tasks = $entityManager->getRepository(Task::class)->findBy([], ['id' => 'DESC']);

        return $this->render('to_do_list/index.html.twig', [
            'tasks' => $tasks,
        ]);
    }

    #[Route('/create', 'create_task', methods: ['POST'])]
    public function create(Request $request, EntityManagerInterface $entityManager): Response
    {

        $title = trim($request->request->get('title'));
        if (empty($title)) {
            return $this->redirectToRoute('to_do_list');
        }

        $task = new Task();
        $task->setTitle($title);
        $entityManager->persist($task);
        $entityManager->flush();

        return $this->redirectToRoute('to_do_list');

    }

    #[Route('/switch-status/{id}', 'switch_task_status')]
    public function switchStatus($id, EntityManagerInterface $entityManager): Response
    {
        $task = $entityManager->getRepository(Task::class)->find($id);

        if (empty($task)) {
            return $this->redirectToRoute('to_do_list');
        }

        $task->setStatus(!$task->getStatus());
        $entityManager->persist($task);
        $entityManager->flush();

        return $this->redirectToRoute('to_do_list');
    }

    #[Route('/delete/{id}', 'delete_task')]
    public function delete($id, EntityManagerInterface $entityManager): Response
    {
        $task = $entityManager->getRepository(Task::class)->find($id);
        $entityManager->remove($task);
        $entityManager->flush();

        return $this->redirectToRoute('to_do_list');

    }
}
