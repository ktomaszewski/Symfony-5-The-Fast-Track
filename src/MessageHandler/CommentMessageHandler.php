<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\ImageOptimizer;
use App\Message\CommentMessage;
use App\Repository\CommentRepository;
use App\SpamChecker;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\NotificationEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Workflow\WorkflowInterface;
use function sprintf;

final class CommentMessageHandler implements MessageHandlerInterface
{
    /** @var CommentRepository */
    private $commentRepository;

    /** @var SpamChecker */
    private $spamChecker;

    /** @var EntityManagerInterface */
    private $entityManager;

    /** @var MessageBusInterface */
    private $messageBus;

    /** @var WorkflowInterface */
    private $workflow;

    /** @var MailerInterface */
    private $mailer;

    /** @var ImageOptimizer */
    private $imageOptimizer;

    /** @var string */
    private $adminEmail;

    /** @var string */
    private $photoDirectoryPath;

    /** @var null|LoggerInterface */
    private $logger;

    public function __construct(CommentRepository $commentRepository, SpamChecker $spamChecker, EntityManagerInterface $entityManager, MessageBusInterface $messageBus, WorkflowInterface $commentStateMachine, MailerInterface $mailer, ImageOptimizer $imageOptimizer, string $adminEmail, string $photoDirectoryPath, LoggerInterface $logger = null)
    {
        $this->commentRepository = $commentRepository;
        $this->spamChecker = $spamChecker;
        $this->entityManager = $entityManager;
        $this->messageBus = $messageBus;
        $this->workflow = $commentStateMachine;
        $this->mailer = $mailer;
        $this->imageOptimizer = $imageOptimizer;
        $this->adminEmail = $adminEmail;
        $this->photoDirectoryPath = $photoDirectoryPath;
        $this->logger = $logger;
    }

    public function __invoke(CommentMessage $commentMessage)
    {
        $comment = $this->commentRepository->find($commentMessage->getId());
        if ($comment === null) {
            return;
        }

        if ($this->workflow->can($comment, 'accept')) {
            $spamScore = $this->spamChecker->getSpamScore($comment, $commentMessage->getContext());
            $this->workflow->apply($comment, $this->resolveTransition($spamScore));
            $this->entityManager->flush();
            $this->messageBus->dispatch($commentMessage);
        } elseif ($this->workflow->can($comment, 'publish') || $this->workflow->can($comment, 'publish_ham')) {
            $this->mailer->send((new NotificationEmail())
                ->subject('New comment posted')
                ->htmlTemplate('emails/comment_notification.html.twig')
                ->to($this->adminEmail)
                ->context(['comment' => $comment])
            );
        } elseif ($this->workflow->can($comment, 'optimize')) {
            if ($comment->getPhotoFilename()) {
                $this->imageOptimizer->resize(sprintf('%s/%s', $this->photoDirectoryPath, $comment->getPhotoFilename()));
            }
            $this->workflow->apply($comment, 'optimize');
            $this->entityManager->flush();
        } elseif ($this->logger !== null) {
            $this->logger->debug('Dropping comment message', ['comment' => $comment->getId(), 'state' => $comment->getState()]);
        }
    }

    private function resolveTransition(int $spamScore): string
    {
        if ($spamScore === SpamChecker::SCORE_BLATANT_SPAM) {
            return 'reject_spam';
        } elseif ($spamScore === SpamChecker::SCORE_MAYBE_SPAM) {
            return 'might_be_spam';
        } else {
            return 'accept';
        }
    }
}
