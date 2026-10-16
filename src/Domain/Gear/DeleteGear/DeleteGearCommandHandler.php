<?php

declare(strict_types=1);

namespace App\Domain\Gear\DeleteGear;

use App\Domain\Gear\GearRepository;
use App\Domain\Gear\GearUsage;
use App\Domain\Image\ImagePath;
use App\Domain\Image\ImageStorage;
use App\Infrastructure\CQRS\Command\Command;
use App\Infrastructure\CQRS\Command\CommandHandler;
use App\Infrastructure\CQRS\Command\CouldNotProcessCommand;

final readonly class DeleteGearCommandHandler implements CommandHandler
{
    public function __construct(
        private GearRepository $gearRepository,
        private GearUsage $gearUsage,
        private ImageStorage $imageStorage,
    ) {
    }

    public function handle(Command $command): void
    {
        assert($command instanceof DeleteGear);

        $gear = $this->gearRepository->find($command->getGearId());

        if ($this->gearUsage->isInUse($gear)) {
            throw CouldNotProcessCommand::withReason('Gear that is still in use cannot be deleted. Retire it instead.');
        }

        $gear->delete();
        $this->gearRepository->delete($gear);

        if (null !== $localImagePath = $gear->getLocalImagePath()) {
            $this->imageStorage->remove(ImagePath::fromLocalImagePath($localImagePath));
        }
    }
}
