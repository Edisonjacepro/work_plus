<?php

namespace App\Form;

use App\Entity\Company;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class CompanyType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nom de l\'entreprise',
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description de l\'entreprise',
                'required' => false,
            ])
            ->add('website', UrlType::class, [
                'label' => 'Site web',
                'required' => false,
            ])
            ->add('city', TextType::class, [
                'label' => 'Ville',
                'required' => false,
            ])
            ->add('sector', TextType::class, [
                'label' => 'Secteur',
                'required' => false,
            ])
            ->add('companySize', ChoiceType::class, [
                'label' => 'Taille de l\'entreprise',
                'required' => false,
                'placeholder' => 'Selectionner une taille',
                'choices' => [
                    '1-10' => '1-10',
                    '11-50' => '11-50',
                    '51-250' => '51-250',
                    '251+' => '251+',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Company::class,
        ]);
    }
}
