<?php

declare(strict_types=1);

namespace App\Profile\Form;

use App\Profile\Dto\BrandingInput;
use App\Profile\Entity\OrganizationBranding;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * FR-071 and FR-072 — logo and brand colour.
 *
 * The colour is a **text** field, not `ColorType`. NFR-064 requires a text hex input beside the
 * picker, and `ColorType` renders `<input type="color">`, which has no text entry, cannot be
 * emptied (so FR-072's reset becomes unreachable), and normalizes silently in some browsers. So
 * the field is the text input the requirement asks for, and the template puts a native colour
 * picker next to it that writes into it — one value, two ways to set it.
 *
 * `pattern` is advisory. `HexColor` parses the value and the DTO's callback validates contrast
 * (NFR-065); a browser-side pattern only saves a round trip.
 */
final class BrandingFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('logo', FileType::class, [
                'label' => 'Logo',
                'required' => false,
                'help' => 'PNG or JPEG, up to 2 MB. Around 200×200 works best; larger images are resized.',
                'attr' => ['accept' => 'image/png,image/jpeg'],
            ])
            ->add('removeLogo', CheckboxType::class, [
                'label' => 'Remove the current logo',
                'required' => false,
            ])
            ->add('primaryColorHex', TextType::class, [
                'label' => 'Primary colour (hex)',
                'required' => false,
                'help' => \sprintf('For example #006600. Leave empty to use the default (%s).', OrganizationBranding::DEFAULT_PRIMARY_COLOR),
                'attr' => [
                    'placeholder' => OrganizationBranding::DEFAULT_PRIMARY_COLOR,
                    'pattern' => '#?([0-9a-fA-F]{3}|[0-9a-fA-F]{6})',
                    'maxlength' => 7,
                    'inputmode' => 'text',
                    'spellcheck' => 'false',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => BrandingInput::class]);
    }
}
