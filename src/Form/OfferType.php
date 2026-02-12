<?php

namespace App\Form;

use App\Entity\Offer;
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
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Offer::class,
        ]);
    }
}
