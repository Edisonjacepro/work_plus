<?php

namespace App\Form;

use App\Entity\Company;
use App\Entity\Offer;
use App\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class OfferType extends AbstractType
{
    private const IMPACT_CATEGORY_CHOICES = [
        'Climat' => 'climat',
        'Biodiversité' => 'biodiversite',
        'Pauvreté' => 'pauvrete',
        'Paix' => 'paix',
    ];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title')
            ->add('description', TextareaType::class)
            ->add('impactCategories', ChoiceType::class, [
                'choices' => self::IMPACT_CATEGORY_CHOICES,
                'multiple' => true,
                'expanded' => true,
                'label' => 'Catégories d’impact',
            ])
            ->add('company', EntityType::class, [
                'class' => Company::class,
                'choice_label' => 'name',
                'placeholder' => 'Sélectionner une entreprise',
            ])
            ->add('author', EntityType::class, [
                'class' => User::class,
                'choice_label' => 'email',
                'placeholder' => 'Sélectionner un auteur',
                'label' => 'Auteur',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Offer::class,
        ]);
    }
}
