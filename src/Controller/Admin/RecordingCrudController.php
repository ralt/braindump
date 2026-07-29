<?php

namespace App\Controller\Admin;

use App\Entity\Recording;
use App\Enum\RecordingStatus;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class RecordingCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Recording::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW, Action::DELETE);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('title');
        yield AssociationField::new('owner');
        yield ChoiceField::new('status')->hideOnForm()->setChoices(RecordingStatus::cases());
        yield IntegerField::new('fileSizeBytes', 'Size (bytes)')->hideOnForm();
        yield IntegerField::new('durationSeconds', 'Duration (s)')->hideOnForm();
        yield TextareaField::new('transcription')->hideOnIndex();
        yield DateTimeField::new('createdAt')->hideOnForm();
    }
}
