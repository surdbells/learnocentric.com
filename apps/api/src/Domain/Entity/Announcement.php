<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Entity\Concern\TimestampsTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A school announcement broadcast to an audience within an institution
 * (spec §16). Posted by staff; read by the targeted audience.
 */
#[ORM\Entity]
#[ORM\Table(name: 'announcements')]
#[ORM\HasLifecycleCallbacks]
class Announcement
{
    use TimestampsTrait;

    /** Audience segments; 'all' means everyone in the institution. */
    public const AUDIENCES = ['all', 'students', 'teachers', 'parents', 'staff'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Institution::class)]
    #[ORM\JoinColumn(name: 'institution_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Institution $institution;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'author_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $author;

    #[ORM\Column(length: 200)]
    private string $title;

    #[ORM\Column(type: Types::TEXT)]
    private string $body;

    #[ORM\Column(length: 20)]
    private string $audience = 'all';

    public function __construct(Institution $institution, ?User $author, string $title, string $body, string $audience = 'all')
    {
        $this->institution = $institution;
        $this->author = $author;
        $this->title = $title;
        $this->body = $body;
        $this->audience = in_array($audience, self::AUDIENCES, true) ? $audience : 'all';
        $this->initTimestamps();
    }

    public function getId(): ?int { return $this->id; }
    public function getAudience(): string { return $this->audience; }
    public function getInstitution(): Institution { return $this->institution; }

    /** Roles that a given audience segment resolves to. */
    public static function rolesForAudience(string $audience): array
    {
        return match ($audience) {
            'students' => ['student'],
            'teachers' => ['teacher'],
            'parents' => ['parent'],
            'staff' => ['school_admin', 'tutor_admin', 'teacher'],
            default => ['student', 'teacher', 'parent', 'school_admin', 'tutor_admin'],
        };
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
            'audience' => $this->audience,
            'author' => $this->author ? trim($this->author->getFirstName() . ' ' . $this->author->getLastName()) : 'School',
            'created_at' => $this->createdAt->format(DATE_ATOM),
        ];
    }
}
