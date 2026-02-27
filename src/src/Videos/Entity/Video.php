<?php

namespace App\Videos\Entity;

use App\Videos\Repository\VideoRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: VideoRepository::class)]
class Video
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[Assert\NotBlank()]
    #[Assert\Length(min: 3, max: 10, minMessage: 'Title must be at least {{ limit }} characters long', maxMessage: 'Title cannot be longer than {{ limit }} characters')]
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTime $created_at = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\File(maxSize: '1024k', mimeTypes: ['video/mp4', 'video/mpeg', 'application/pdf'], mimeTypesMessage: 'Please upload a valid video file (MP4, MPEG, or PDF)')]
    private ?string $file = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getCreatedAt(): ?\DateTime
    {
        return $this->created_at;
    }

    public function setCreatedAt(\DateTime $created_at): static
    {
        $this->created_at = $created_at;

        return $this;
    }

    public function getfile(): ?string
    {
        return $this->file;
    }

    public function setfile(string $file): static
    {
        $this->file = $file;

        return $this;
    }
}
