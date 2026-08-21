<?php

namespace App\Products\Infrastructure\Controller;

use App\Products\Domain\Entity\Category;
use App\Products\Domain\Entity\Product;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class ProductController extends AbstractController
{
    #[Route('/products', name: 'product_list')]
    public function list(Request $request, EntityManagerInterface $entityManager): Response
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = 30;

        $repo = $entityManager->getRepository(Product::class);
        $paginator = $repo->findAllWithCategoryPaginated($page, $limit);

        $totalItems = count($paginator);
        $pagesCount = (int) ceil($totalItems / $limit);

        return $this->render('product/list.html.twig', [
            'products' => $paginator,
            'currentPage' => $page,
            'pagesCount' => $pagesCount,
        ]);
    }

    #[Route('/product/create', name: 'create_product')]
    public function createProduct(EntityManagerInterface $entityManager, ValidatorInterface $validator): Response
    {
        try {
            $category = new Category();
            $category->setName('Computer Peripherals');

            $product = new Product();
            $product->setName('Keyboard');
            $product->setPrice(1999);
            $product->setDescription('Ergonomic and stylish!');
            $product->setCategory($category);

            $errors = $validator->validate($product);

            if (\count($errors) > 0) {
                $messages = [];
                foreach ($errors as $error) {
                    $messages[] = $error->getPropertyPath() . ': ' . $error->getMessage();
                }

                return new Response(implode("\n", $messages), 400);
            }

            $entityManager->persist($category);
            $entityManager->persist($product);
            $entityManager->flush();
        } catch (\Exception $e) {
            return new Response('Error: ' . $e->getMessage());
        }

        return new Response('Saved new product with id ' . $product->getId());
    }

    #[Route('/product/{id<\d+>}', name: 'product_show')]
    public function show(EntityManagerInterface $entityManager, int $id): Response
    {
        $repository = $entityManager->getRepository(Product::class);

        $product = $repository->find($id);
        //            $product = $repository->findOneBy(['name' => 'Keyboard']);
        //            $product = $repository->findOneBy([
        //                'name' => 'Keyboard',
        //                'price' => 1999,
        //            ]);
        //            $products = $repository->findBy(
        //                ['name' => 'Keyboard'],
        //                ['price' => 'ASC']
        //            );

        if (!$product) {
            throw $this->createNotFoundException('No product found for id ' . $id);
        }

        $category = $product->getCategory();
        $category_name = $category->getName();

        return new Response('Check out this great product: ' . $product->getName() . ' in ' . $category_name . ' category');
    }

    #[Route('/product/{id}/edit', name: 'product_edit')]
    public function update(EntityManagerInterface $entityManager, int $id): Response
    {
        $repository = $entityManager->getRepository(Product::class);
        $product = $repository->find($id);

        if (!$product) {
            throw $this->createNotFoundException('No product found');
        }

        $product->setName('New product name!');
        $entityManager->flush();

        return $this->redirectToRoute('product_show', [
            'id' => $product->getId(),
        ]);
    }

    #[Route('/product/find ', name: 'product_find')]
    public function find(EntityManagerInterface $entityManager): Response
    {
        $repository = $entityManager->getRepository(Product::class);
        $minPrice = 1500;
        $products = $repository->findAllGreaterThanPrice2($minPrice);

        if (!$products) {
            throw $this->createNotFoundException('No products found');
        }

        return $this->json($products);
    }

    #[Route('/product/{id}/delete', name: 'product_delete')]
    public function delete(EntityManagerInterface $entityManager, int $id): Response
    {
        $repository = $entityManager->getRepository(Product::class);
        $product = $repository->find($id);

        if (!$product) {
            throw $this->createNotFoundException('No product found');
        }

        $entityManager->remove($product);
        $entityManager->flush();

        return new Response('Deleted product with id ' . $id);
    }
}
