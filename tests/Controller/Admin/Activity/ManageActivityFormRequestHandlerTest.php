<?php

namespace App\Tests\Controller\Admin\Activity;

use App\Domain\Activity\ActivityId;
use App\Domain\Activity\ActivityRepository;
use App\Domain\Activity\ActivityWithRawData;
use App\Domain\Activity\ImportSource;
use App\Domain\Activity\SportType\SportType;
use App\Domain\Activity\WorkoutType;
use App\Domain\Gear\GearId;
use App\Domain\Gear\GearRepository;
use App\Domain\Gear\GearType;
use App\Domain\Settings\SettingsGroup;
use App\Domain\Settings\SettingsRepository;
use App\Infrastructure\Measurement\Length\Kilometer;
use App\Infrastructure\Measurement\Length\Meter;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use App\Tests\Controller\Admin\AdminWebTestCase;
use App\Tests\Domain\Activity\ActivityBuilder;
use App\Tests\Domain\Gear\GearBuilder;
use PHPUnit\Framework\Attributes\DataProvider;

class ManageActivityFormRequestHandlerTest extends AdminWebTestCase
{
    public function testRendersTheAddFormInFilesMode(): void
    {
        static::getContainer()->get(SettingsRepository::class)->saveGroup(SettingsGroup::APPEARANCE, [
            'sportTypesSortingOrder' => [SportType::WALK->value, SportType::RUN->value],
        ]);

        static::getContainer()->get(GearRepository::class)->add(
            GearBuilder::fromDefaults()
                ->withGearId(GearId::fromUnprefixed('custom-gear'))
                ->withGearType(GearType::CUSTOM)
                ->withName('My Custom Gear')
                ->build()
        );

        $this->client->loginUser($this->adminUser());

        $crawler = $this->client->request('GET', '/admin/activities/add');

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Add activity', $crawler->filter('h3')->text());

        $form = $crawler->filter('form[data-dispatch-command="manually-create-activity"]');
        $this->assertCount(1, $form);

        // Nothing is prefilled and no Strava-sourced field is disabled.
        $this->assertCount(0, $crawler->filter('input[name="activityId"]'));
        $this->assertSame('', (string) $crawler->filter('input#activity-name')->attr('value'));
        $this->assertNull($crawler->filter('input#activity-name')->attr('disabled'));

        // The fields that only exist on the manual form.
        $this->assertCount(1, $form->filter('input[name="startDateTime"][type="datetime-local"]'));
        $this->assertCount(1, $form->filter('input[name="distance"]'));
        $this->assertCount(1, $form->filter('input[name="elevation"]'));
        $this->assertCount(1, $form->filter('input[name="calories"]'));
        $this->assertCount(1, $form->filter('input[name="averageHeartRate"]'));
        $this->assertCount(1, $form->filter('input[name="maxHeartRate"]'));
        $this->assertCount(1, $form->filter('input[name="duration[hours]"]'));
        $this->assertCount(1, $form->filter('input[name="duration[minutes]"]'));
        $this->assertCount(1, $form->filter('input[name="duration[seconds]"]'));

        $this->assertSame(
            ['None', 'Race', 'Workout', 'Long run'],
            $crawler->filter('select#activity-workout-type option')->each(fn ($option) => $option->text()),
        );

        // Every sport type can be picked, in the order configured by the user.
        $sportTypeOptions = $crawler->filter('select#activity-sport-type option')->each(fn ($option) => $option->attr('value'));
        $this->assertCount(count(SportType::cases()), $sportTypeOptions);
        $this->assertSame([SportType::WALK->value, SportType::RUN->value], array_slice($sportTypeOptions, 0, 2));

        // Distance and elevation are entered in the configured unit system.
        $this->assertStringContainsString('(km)', $crawler->filter('label[for="activity-distance"]')->text());
        $this->assertStringContainsString('(m)', $crawler->filter('label[for="activity-elevation"]')->text());

        $this->assertSame(
            ['None', 'My Custom Gear'],
            $crawler->filter('select#activity-gear option')->each(fn ($option) => $option->text()),
        );

        // Images can be attached right away.
        $this->assertCount(1, $crawler->filter('[data-image-dropzone]'));

        $this->assertCount(0, $crawler->filter('a.btn--danger'));
    }

    public function testRendersTheManuallyAddedActivityFormPrefilledOnEdit(): void
    {
        static::getContainer()->get(GearRepository::class)->add(
            GearBuilder::fromDefaults()
                ->withGearId(GearId::fromUnprefixed('custom-gear'))
                ->withGearType(GearType::CUSTOM)
                ->withName('My Custom Gear')
                ->build()
        );

        static::getContainer()->get(ActivityRepository::class)->add(ActivityWithRawData::fromState(
            ActivityBuilder::fromDefaults()
                ->withActivityId(ActivityId::fromUnprefixed('1'))
                ->withImportSource(ImportSource::MANUAL)
                ->withName('My manual activity')
                ->withSportType(SportType::RUN)
                ->withWorkoutType(WorkoutType::RACE)
                ->withStartDateTime(SerializableDateTime::fromString('2023-10-17 16:15:00'))
                ->withMovingTimeInSeconds(3723)
                ->withDistance(Kilometer::from(10.5))
                ->withElevation(Meter::from(120))
                ->withCalories(750)
                ->withAverageHeartRate(140)
                ->withMaxHeartRate(172)
                ->withGearId(GearId::fromUnprefixed('custom-gear'))
                ->withIsCommute(true)
                ->withIsGroupActivity(true)
                ->withLocalImagePaths('activity-1/image.jpg')
                ->build(),
            [],
        ));

        $this->client->loginUser($this->adminUser());

        $crawler = $this->client->request('GET', '/admin/activities/'.ActivityId::fromUnprefixed('1').'/edit');

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Edit activity', $crawler->filter('h3')->text());

        // Manually added activities are updated through the form they were added with.
        $form = $crawler->filter('form[data-dispatch-command="update-manually-added-activity"]');
        $this->assertCount(1, $form);

        $this->assertSame((string) ActivityId::fromUnprefixed('1'), $form->filter('input[name="activityId"]')->attr('value'));
        $this->assertSame('My manual activity', $form->filter('input[name="name"]')->attr('value'));
        $this->assertNotNull($form->filter('select#activity-sport-type option[value="Run"]')->attr('selected'));
        $this->assertNotNull($form->filter('select#activity-workout-type option[value="race"]')->attr('selected'));
        $this->assertSame('2023-10-17T16:15', $form->filter('input[name="startDateTime"]')->attr('value'));

        // 3723 seconds, split over the three duration inputs.
        $this->assertSame('1', $form->filter('input[name="duration[hours]"]')->attr('value'));
        $this->assertSame('2', $form->filter('input[name="duration[minutes]"]')->attr('value'));
        $this->assertSame('3', $form->filter('input[name="duration[seconds]"]')->attr('value'));

        // Distance and elevation are rendered in the configured unit system.
        $this->assertSame('10.5', $form->filter('input[name="distance"]')->attr('value'));
        $this->assertSame('120', $form->filter('input[name="elevation"]')->attr('value'));
        $this->assertSame('750', $form->filter('input[name="calories"]')->attr('value'));
        $this->assertSame('140', $form->filter('input[name="averageHeartRate"]')->attr('value'));
        $this->assertSame('172', $form->filter('input[name="maxHeartRate"]')->attr('value'));

        $this->assertNotNull($form->filter('select#activity-gear option[value="gear-custom-gear"]')->attr('selected'));
        $this->assertNotNull($form->filter('input#activity-is-commute')->attr('checked'));
        $this->assertNotNull($form->filter('input#activity-is-group-activity')->attr('checked'));

        // A manually added activity was never recorded, so there is no device to pick.
        $this->assertCount(0, $form->filter('select#activity-device-name'));

        // Nothing is locked down, manually added activities own all their data.
        $this->assertNull($crawler->filter('input#activity-name')->attr('disabled'));
        $this->assertNull($crawler->filter('select#activity-sport-type')->attr('disabled'));

        // The existing image can be managed.
        $this->assertCount(1, $crawler->filter('[data-image-dropzone]'));
        $this->assertStringContainsString('image.jpg', (string) $crawler->filter('[data-image-dropzone]')->attr('data-existing-images'));

        $this->assertSame(
            '/admin/activities/activity-1/delete',
            $crawler->filter('a.btn--danger')->attr('href'),
        );
    }

    public function testRendersTheEditFormPrefilledWithTheActivity(): void
    {
        static::getContainer()->get(ActivityRepository::class)->add(ActivityWithRawData::fromState(
            ActivityBuilder::fromDefaults()
                ->withActivityId(ActivityId::fromUnprefixed('1'))
                ->withName('Morning Run')
                ->withWorkoutType(WorkoutType::RACE)
                ->withGearId(GearId::fromUnprefixed('5'))
                ->withDeviceName('Garmin Edge')
                ->withCalories(500)
                ->withIsCommute(true)
                ->withIsGroupActivity(true)
                ->withLocalImagePaths('activity-1/image.jpg')
                ->build(),
            [],
        ));

        $this->client->loginUser($this->adminUser());

        $crawler = $this->client->request('GET', '/admin/activities/'.ActivityId::fromUnprefixed('1').'/edit');

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Edit activity', $crawler->filter('h3')->text());

        $form = $crawler->filter('form[data-dispatch-command="update-activity"]');
        $this->assertCount(1, $form);

        $this->assertSame((string) ActivityId::fromUnprefixed('1'), $form->filter('input[name="activityId"]')->attr('value'));
        $this->assertSame('Morning Run', $form->filter('input[name="name"]')->attr('value'));

        // Every field is editable.
        foreach (['input#activity-name', 'select#activity-sport-type', 'select#activity-workout-type', 'select#activity-gear', 'select#activity-device-name', 'input#activity-calories', 'input#activity-is-commute', 'input#activity-is-group-activity'] as $field) {
            $this->assertNull($crawler->filter($field)->attr('disabled'), $field);
        }
        $this->assertCount(1, $crawler->filter('[data-image-dropzone]'));

        $this->assertCount(1, $crawler->filter('a.btn--danger'));
    }

    #[DataProvider('provideRedirectToQueryParams')]
    public function testItHonoursASafeRedirectToOnEveryExit(string $redirectTo, string $expected): void
    {
        static::getContainer()->get(ActivityRepository::class)->add(ActivityWithRawData::fromState(
            ActivityBuilder::fromDefaults()
                ->withActivityId(ActivityId::fromUnprefixed('1'))
                ->build(),
            [],
        ));

        $this->client->loginUser($this->adminUser());

        $crawler = $this->client->request(
            'GET',
            '/admin/activities/'.ActivityId::fromUnprefixed('1').'/edit?redirectTo='.urlencode($redirectTo)
        );

        $this->assertResponseIsSuccessful();

        $this->assertSame($expected, $crawler->filter('form[data-dispatch-command]')->attr('data-redirect'));
        $this->assertSame($expected, $crawler->filter('a[aria-label="Close"]')->attr('href'));
        $this->assertSame($expected, $crawler->filter('a.btn--secondary')->attr('href'));
    }

    #[DataProvider('provideRedirectToQueryParams')]
    public function testDeleteHonoursASafeRedirectToOnEveryExit(string $redirectTo, string $expected): void
    {
        static::getContainer()->get(ActivityRepository::class)->add(ActivityWithRawData::fromState(
            ActivityBuilder::fromDefaults()
                ->withActivityId(ActivityId::fromUnprefixed('1'))
                ->build(),
            [],
        ));

        $this->client->loginUser($this->adminUser());

        $crawler = $this->client->request(
            'GET',
            '/admin/activities/'.ActivityId::fromUnprefixed('1').'/delete?redirectTo='.urlencode($redirectTo)
        );

        $this->assertResponseIsSuccessful();

        $this->assertSame($expected, $crawler->filter('form[data-dispatch-command]')->attr('data-redirect'));
        $this->assertSame($expected, $crawler->filter('a[aria-label="Close"]')->attr('href'));
        $this->assertSame($expected, $crawler->filter('a.btn--secondary')->attr('href'));
    }

    public static function provideRedirectToQueryParams(): \Generator
    {
        yield 'a path within the app' => ['/activities/activity-1', '/activities/activity-1'];
        yield 'a filtered overview' => ['/admin/activities?filters%5BsportType%5D=Run&pagination%5Bpage%5D=2', '/admin/activities?filters%5BsportType%5D=Run&pagination%5Bpage%5D=2'];
        yield 'absolute url' => ['https://evil.com', '/admin/activities'];
    }

    public function testFilesModeKeepsFieldsEditableAndShowsImages(): void
    {
        $gearRepository = static::getContainer()->get(GearRepository::class);
        $gearRepository->add(
            GearBuilder::fromDefaults()
                ->withGearId(GearId::fromUnprefixed('b5'))
                ->withName('Strava Bike')
                ->build()
        );
        $gearRepository->add(
            GearBuilder::fromDefaults()
                ->withGearId(GearId::fromUnprefixed('custom-gear'))
                ->withGearType(GearType::CUSTOM)
                ->withName('My Custom Gear')
                ->build()
        );

        static::getContainer()->get(ActivityRepository::class)->add(ActivityWithRawData::fromState(
            ActivityBuilder::fromDefaults()
                ->withActivityId(ActivityId::fromUnprefixed('1'))
                ->withName('Morning Run')
                ->withWorkoutType(WorkoutType::RACE)
                ->withIsCommute(false)
                ->build(),
            [],
        ));

        $this->client->loginUser($this->adminUser());

        $crawler = $this->client->request('GET', '/admin/activities/'.ActivityId::fromUnprefixed('1').'/edit');

        $this->assertResponseIsSuccessful();

        $this->assertNull($crawler->filter('input#activity-name')->attr('disabled'));
        $this->assertNull($crawler->filter('select#activity-sport-type')->attr('disabled'));
        $this->assertNull($crawler->filter('select#activity-workout-type')->attr('disabled'));
        $this->assertNull($crawler->filter('select#activity-gear')->attr('disabled'));
        $this->assertNull($crawler->filter('select#activity-device-name')->attr('disabled'));
        $this->assertNull($crawler->filter('input#activity-calories')->attr('disabled'));
        $this->assertNull($crawler->filter('input#activity-is-commute')->attr('disabled'));

        $this->assertCount(0, $crawler->filter('input[type="hidden"][name="name"]'));
        $this->assertCount(0, $crawler->filter('input[type="hidden"][name="sportType"]'));
        $this->assertCount(0, $crawler->filter('input[type="hidden"][name="workoutType"]'));
        $this->assertSame(
            ['None', 'Race', 'Workout', 'Long run'],
            $crawler->filter('select#activity-workout-type option')->each(fn ($option) => $option->text()),
        );
        $this->assertNotNull($crawler->filter('select#activity-workout-type option[value="race"]')->attr('selected'));
        $this->assertCount(0, $crawler->filter('input[type="hidden"][name="gearId"]'));
        $this->assertCount(0, $crawler->filter('input[type="hidden"][name="deviceName"]'));
        $this->assertCount(0, $crawler->filter('input[type="hidden"][name="calories"]'));
        $this->assertSame('false', $crawler->filter('input[type="hidden"][name="isCommute"]')->attr('value'));

        // In files mode all gears are selectable, imported or custom.
        $this->assertSame(
            ['None', 'Strava Bike', 'My Custom Gear'],
            $crawler->filter('select#activity-gear option')->each(fn ($option) => $option->text()),
        );

        // The image upload is available.
        $this->assertCount(1, $crawler->filter('[data-image-dropzone]'));

        $this->assertSame(
            '/admin/activities/activity-1/delete',
            $crawler->filter('a.btn--danger')->attr('href'),
        );
    }

    public function testRendersTheDeleteConfirmationForTheActivity(): void
    {
        static::getContainer()->get(ActivityRepository::class)->add(ActivityWithRawData::fromState(
            ActivityBuilder::fromDefaults()
                ->withActivityId(ActivityId::fromUnprefixed('1'))
                ->withName('Morning Run')
                ->build(),
            [],
        ));

        $this->client->loginUser($this->adminUser());

        $crawler = $this->client->request('GET', '/admin/activities/'.ActivityId::fromUnprefixed('1').'/delete');

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Delete activity', $crawler->filter('h3')->text());
        $this->assertStringContainsString('Morning Run', $crawler->filter('body')->text());

        $form = $crawler->filter('form[data-dispatch-command="delete-activity"]');
        $this->assertCount(1, $form);

        $this->assertSame((string) ActivityId::fromUnprefixed('1'), $form->filter('input[name="activityId"]')->attr('value'));
    }
}
