<?php

namespace App\Form;

use App\Entity\ModerationReview;
use App\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ModerationDecisionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('decision', ChoiceType::class, [
                'choices' => [
                    'Approuver' => ModerationReview::DECISION_APPROVED,
                    'Rejeter' => ModerationReview::DECISION_REJECTED,
                ],
                'expanded' => true,
            ])
            ->add('reason', TextareaType::class, [
                'required' => false,
                'help' => 'Obligatoire si rejet.',
            ])
            ->add('reviewer', EntityType::class, [
                'class' => User::class,
                'choice_label' => 'email',
                'placeholder' => 'Sélectionner un reviewer',
                'label' => 'Reviewer',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ModerationReview::class,
        ]);
    }
}
