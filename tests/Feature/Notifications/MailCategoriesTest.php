<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Feature\Notifications;

use App\Application\Mail\Contracts\HasMailCategory;
use App\Application\Mail\Services\MailCategoryRegistryInterface;
use Illuminate\Notifications\Notification;
use Modules\Tournaments\Notifications\GuestConfirmationRequestNotification;
use Modules\Tournaments\Notifications\GuestRegistrationExpiredNotification;
use Modules\Tournaments\Notifications\MatchResultDisputedNotification;
use Modules\Tournaments\Notifications\MatchResultReportedNotification;
use Modules\Tournaments\Notifications\ParticipantConfirmedNotification;
use Modules\Tournaments\Notifications\ParticipantDisqualifiedNotification;
use Modules\Tournaments\Notifications\ParticipantRegisteredToOrganizer;
use Modules\Tournaments\Notifications\ParticipantRegisteredToParticipant;
use Modules\Tournaments\Notifications\ParticipantWithdrawnToOrganizer;
use Modules\Tournaments\Notifications\ParticipantWithdrawnToParticipant;
use Modules\Tournaments\Notifications\TournamentCancelledNotification;
use Modules\Tournaments\Notifications\TournamentReminderNotification;
use Modules\Tournaments\TournamentsServiceProvider;
use ReflectionClass;
use Tests\Support\Modules\ModuleTestCase;

/**
 * Every mail the module sends to an account belongs to a registered category, so people choose
 * what they receive; mail to addresses that are not accounts (guests, the organization's
 * mailbox) has none, because there is no one to choose.
 */
final class MailCategoriesTest extends ModuleTestCase
{
    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    /**
     * Notifications that only ever go to a plain address.
     *
     * @var list<class-string<Notification>>
     */
    private const array WITHOUT_CATEGORY = [
        GuestConfirmationRequestNotification::class,
        GuestRegistrationExpiredNotification::class,
        ParticipantRegisteredToOrganizer::class,
        ParticipantWithdrawnToOrganizer::class,
    ];

    /**
     * @return array<class-string<Notification>, string>
     */
    private function expected(): array
    {
        return [
            ParticipantRegisteredToParticipant::class => TournamentsServiceProvider::MAIL_REGISTRATIONS,
            ParticipantConfirmedNotification::class => TournamentsServiceProvider::MAIL_REGISTRATIONS,
            ParticipantWithdrawnToParticipant::class => TournamentsServiceProvider::MAIL_REGISTRATIONS,
            ParticipantDisqualifiedNotification::class => TournamentsServiceProvider::MAIL_REGISTRATIONS,
            TournamentCancelledNotification::class => TournamentsServiceProvider::MAIL_REGISTRATIONS,
            MatchResultReportedNotification::class => TournamentsServiceProvider::MAIL_RESULTS,
            MatchResultDisputedNotification::class => TournamentsServiceProvider::MAIL_RESULTS,
            TournamentReminderNotification::class => TournamentsServiceProvider::MAIL_REMINDERS,
        ];
    }

    public function test_every_notification_is_classified(): void
    {
        $classified = [...array_keys($this->expected()), ...self::WITHOUT_CATEGORY];

        foreach (glob(dirname(__DIR__, 3).'/src/Notifications/*.php') ?: [] as $file) {
            $class = 'Modules\\Tournaments\\Notifications\\'.basename($file, '.php');

            if (! is_subclass_of($class, Notification::class)) {
                continue;
            }

            $this->assertContains($class, $classified, $class.' has neither a mail category nor a place in the list of plain-address mail');
        }
    }

    public function test_each_notification_reports_its_category(): void
    {
        foreach ($this->expected() as $class => $key) {
            $notification = (new ReflectionClass($class))->newInstanceWithoutConstructor();

            $this->assertInstanceOf(HasMailCategory::class, $notification, $class);
            $this->assertSame($key, $notification->mailCategory(), $class);
        }
    }

    public function test_notifications_without_category_do_not_declare_one(): void
    {
        foreach (self::WITHOUT_CATEGORY as $class) {
            $this->assertNotInstanceOf(HasMailCategory::class, (new ReflectionClass($class))->newInstanceWithoutConstructor(), $class);
        }
    }

    public function test_the_categories_are_registered_with_the_right_kind(): void
    {
        $registry = $this->app->make(MailCategoryRegistryInterface::class);

        foreach ([TournamentsServiceProvider::MAIL_REGISTRATIONS, TournamentsServiceProvider::MAIL_RESULTS] as $key) {
            $category = $registry->find($key);
            $this->assertNotNull($category, $key);
            $this->assertTrue($category->isMandatory(), $key);
            $this->assertNotNull($category->mandatoryReason, $key);
        }

        $reminders = $registry->find(TournamentsServiceProvider::MAIL_REMINDERS);
        $this->assertNotNull($reminders);
        $this->assertFalse($reminders->isMandatory());
    }

    public function test_the_category_texts_are_translated(): void
    {
        app()->setLocale('es');

        foreach ($this->app->make(MailCategoryRegistryInterface::class)->all() as $category) {
            if (! str_starts_with($category->key, 'tournaments.')) {
                continue;
            }

            foreach ([$category->section, $category->label, $category->description, $category->mandatoryReason] as $text) {
                if ($text !== null) {
                    $this->assertNotSame($text, __($text), $text);
                }
            }
        }
    }
}
