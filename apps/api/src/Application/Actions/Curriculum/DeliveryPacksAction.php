<?php

declare(strict_types=1);

namespace App\Application\Actions\Curriculum;

use App\Application\Actions\School\ResolvesInstitution;
use App\Application\Support\Json;
use App\Application\Support\ListQuery;
use App\Application\Support\Paginator;
use App\Domain\Entity\ContentVersion;
use App\Domain\Entity\Topic;
use App\Domain\Entity\TopicDeliveryPack;
use App\Domain\Lifecycle;
use App\Service\AuditLogger;
use App\Service\LifecycleService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

/** /backend/curriculum/delivery-packs — author lesson packs (staff), lifecycle-governed. */
final class DeliveryPacksAction
{
    use ResolvesInstitution;

    private const EDITABLE = [Lifecycle::DRAFT, Lifecycle::REVIEW];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
        private readonly LifecycleService $lifecycle,
    ) {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        return match (strtoupper($request->getMethod())) {
            'POST' => $this->create($request, $response),
            'PUT' => $this->update($request, $response),
            'DELETE' => $this->delete($request, $response),
            default => $this->list($request, $response),
        };
    }

    private function list(Request $request, Response $response): Response
    {
        $institution = $this->resolveInstitution($request, $this->em);
        $query = ListQuery::fromRequest($request, ['status', 'created_at'], ['topic_id', 'subject_id', 'status'], 'created_at');

        $qb = $this->em->createQueryBuilder()->select('p')->from(TopicDeliveryPack::class, 'p')->join('p.topic', 't')->join('t.subject', 's');
        if ($institution !== null) {
            $qb->andWhere('s.institution = :inst')->setParameter('inst', $institution);
        }
        if ($query->q !== '') {
            $qb->andWhere('LOWER(t.title) LIKE :q')->setParameter('q', '%' . strtolower($query->q) . '%');
        }
        if (isset($query->filters['status'])) {
            $qb->andWhere('p.status = :st')->setParameter('st', $query->filters['status']);
        }
        if (isset($query->filters['topic_id'])) {
            $qb->andWhere('p.topic = :tid')->setParameter('tid', (int) $query->filters['topic_id']);
        }
        if (isset($query->filters['subject_id'])) {
            $qb->andWhere('t.subject = :sid')->setParameter('sid', (int) $query->filters['subject_id']);
        }

        $mapper = fn (TopicDeliveryPack $p) => $this->row($p);
        if (!$query->paginated) {
            $qb->orderBy('p.id', 'DESC');
            return Json::write($response, array_map($mapper, $qb->getQuery()->getResult()));
        }

        return Json::write($response, Paginator::paginate($qb, 'p', $query, ['status' => 'p.status', 'created_at' => 'p.id'], $mapper));
    }

    private function create(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $topic = $this->em->getRepository(Topic::class)->find((int) ($body['topic_id'] ?? 0));
        if ($topic === null) {
            return Json::error($response, 'A valid topic_id is required.', 422);
        }
        if ($this->em->getRepository(TopicDeliveryPack::class)->findOneBy(['topic' => $topic]) !== null) {
            return Json::error($response, 'This topic already has a delivery pack.', 409);
        }
        $pack = new TopicDeliveryPack($topic);
        $pack->setStatus(Lifecycle::DRAFT);
        $this->applyFields($pack, $body);
        $this->em->persist($pack);
        $this->em->flush();
        $this->audit->log('deliverypack.create', $request->getAttribute('user'), 'TopicDeliveryPack', (string) $pack->getId(), null, $pack->toArray());

        return Json::write($response, $this->row($pack), 201);
    }

    private function update(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $pack = $this->em->getRepository(TopicDeliveryPack::class)->find((int) ($body['id'] ?? 0));
        if ($pack === null) {
            return Json::error($response, 'Delivery pack not found.', 404);
        }
        if (!in_array($pack->getStatus(), self::EDITABLE, true)) {
            return Json::error($response, "This pack is {$pack->getStatus()}; return it to draft before editing.", 409);
        }
        $before = $pack->toArray();
        $this->applyFields($pack, $body);
        $this->em->flush();
        $this->audit->log('deliverypack.update', $request->getAttribute('user'), 'TopicDeliveryPack', (string) $pack->getId(), $before, $pack->toArray());

        return Json::write($response, $this->row($pack));
    }

    private function delete(Request $request, Response $response): Response
    {
        $id = (int) ($request->getQueryParams()['id'] ?? 0);
        $pack = $this->em->getRepository(TopicDeliveryPack::class)->find($id);
        if ($pack === null) {
            return Json::error($response, 'Delivery pack not found.', 404);
        }
        $this->em->remove($pack);
        $this->em->flush();
        $this->audit->log('deliverypack.delete', $request->getAttribute('user'), 'TopicDeliveryPack', (string) $id, null, null);

        return Json::write($response, ['deleted' => true, 'id' => $id]);
    }

    /**
     * GET /curriculum/delivery-packs/{id} — the full pack for its detail page:
     * content, a materials-readiness checklist, the parent topic's context
     * (objective + misconception watch) and the lifecycle history.
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        $pack = $this->em->getRepository(TopicDeliveryPack::class)->find((int) $args['id']);
        if ($pack === null) {
            return Json::error($response, 'Delivery pack not found.', 404);
        }
        $arr = $pack->toArray();
        $topic = $pack->getTopic();
        $ta = $topic->toArray();

        $materials = [
            'teacher_guide' => !empty($arr['teacher_guide']),
            'learner_note' => !empty($arr['learner_note']),
            'video' => !empty($arr['video_url']),
            'worked_examples' => !empty($arr['worked_examples']),
            'parent_wording' => !empty($arr['parent_wording']),
        ];
        $present = count(array_filter($materials));
        $total = count($materials);

        return Json::write($response, [
            'pack' => $this->row($pack),
            'topic' => [
                'id' => $topic->getId(),
                'title' => $ta['title'],
                'subject' => $ta['subject'],
                'class_label' => $ta['class_label'],
                'week_number' => $ta['week_number'],
                'objective' => $ta['objective'],
                'real_life_relevance' => $ta['real_life_relevance'],
                'misconceptions' => is_array($ta['misconceptions']) ? $ta['misconceptions'] : [],
            ],
            'readiness' => [
                'materials' => $materials,
                'present' => $present,
                'total' => $total,
                'percent' => (int) round($present / $total * 100),
                'ready' => $pack->getStatus() === Lifecycle::PUBLISHED,
            ],
            'history' => array_map(static fn ($v) => $v->toArray(), $this->lifecycle->history($pack)),
        ]);
    }

    public function transition(Request $request, Response $response, array $args): Response
    {
        $pack = $this->em->getRepository(TopicDeliveryPack::class)->find((int) $args['id']);
        if ($pack === null) {
            return Json::error($response, 'Delivery pack not found.', 404);
        }
        $body = (array) $request->getParsedBody();
        try {
            $result = $this->lifecycle->transition($pack, (string) ($body['to'] ?? ''), $request->getAttribute('user'), $body['note'] ?? null);
        } catch (Throwable $e) {
            return Json::error($response, $e->getMessage(), 422);
        }

        return Json::write($response, ['pack' => $this->row($pack)] + $result);
    }

    public function history(Request $request, Response $response, array $args): Response
    {
        $pack = $this->em->getRepository(TopicDeliveryPack::class)->find((int) $args['id']);
        if ($pack === null) {
            return Json::error($response, 'Delivery pack not found.', 404);
        }

        return Json::write($response, array_map(static fn ($v) => $v->toArray(), $this->lifecycle->history($pack)));
    }

    public function bulkDelete(Request $request, Response $response): Response
    {
        $ids = array_values(array_filter(array_map('intval', (array) (($request->getParsedBody()['ids'] ?? [])))));
        if (empty($ids)) {
            return Json::error($response, 'No ids provided.', 422);
        }
        $count = 0;
        foreach ($this->em->getRepository(TopicDeliveryPack::class)->findBy(['id' => $ids]) as $pack) {
            $this->em->remove($pack);
            $count++;
        }
        $this->em->flush();

        return Json::write($response, ['deleted' => $count]);
    }

    private function applyFields(TopicDeliveryPack $pack, array $body): void
    {
        foreach ([
            'teacher_guide' => 'setTeacherGuide',
            'learner_note' => 'setLearnerNote',
            'video_url' => 'setVideoUrl',
            'worked_examples' => 'setWorkedExamples',
            'parent_wording' => 'setParentWording',
        ] as $key => $setter) {
            if (array_key_exists($key, $body)) {
                $pack->{$setter}($body[$key] !== '' ? (string) $body[$key] : null);
            }
        }
        if (array_key_exists('media', $body)) {
            $pack->setMedia(is_array($body['media']) ? $body['media'] : null);
        }
        if (isset($body['version']) && $body['version'] !== '') {
            $pack->setVersion((string) $body['version']);
        }
    }

    private function row(TopicDeliveryPack $pack): array
    {
        return $pack->toArray() + [
            'subject' => $pack->getTopic()->getSubject()->getName(),
            'next_states' => Lifecycle::nextStates($pack->getStatus()),
            'version_count' => $this->em->getRepository(ContentVersion::class)->count(['entityType' => 'TopicDeliveryPack', 'entityId' => $pack->getId()]),
        ];
    }
}
