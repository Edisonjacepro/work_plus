<?php

namespace App\Form;

use App\Entity\Offer;
use App\Entity\PointsClaim;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\File;

class PointsClaimType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('claimType', ChoiceType::class, [
                'label' => 'Type de preuve',
                'choices' => [
                    'Certificat' => PointsClaim::CLAIM_TYPE_CERTIFICATION,
                    'Formation' => PointsClaim::CLAIM_TYPE_TRAINING,
                    'Benevolat' => PointsClaim::CLAIM_TYPE_VOLUNTEERING,
                    'Autre preuve' => PointsClaim::CLAIM_TYPE_OTHER,
                ],
            ])
            ->add('offer', EntityType::class, [
                'class' => Offer::class,
                'choices' => $options['offer_choices'],
                'required' => false,
                'placeholder' => 'Aucune offre specifique',
                'choice_label' => static function (Offer $offer): string {
                    return sprintf('#%d - %s', (int) $offer->getId(), (string) $offer->getTitle());
                },
                'label' => 'Offre liee (optionnel)',
            ])
            ->add('evidenceIssuedAt', DateType::class, [
                'label' => 'Date de la preuve',
                'widget' => 'single_text',
                'required' => true,
            ])
            ->add('evidenceFiles', FileType::class, [
                'label' => 'Pieces justificatives',
                'mapped' => false,
                'required' => true,
                'multiple' => true,
                'constraints' => [
                    new All([
                        'constraints' => [
                            new File([
                                'maxSize' => '8M',
                                'mimeTypes' => [
                                    'application/pdf',
                                    'application/msword',
                                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                                    'application/vnd.oasis.opendocument.text',
                                    'image/jpeg',
                                    'image/png',
                                    'text/plain',
                                ],
                                'mimeTypesMessage' => 'Format de fichier non autorise.',
                            ]),
                        ],
                    ]),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PointsClaim::class,
            'offer_choices' => [],
        ]);

        $resolver->setAllowedTypes('offer_choices', 'array');
    }
}
