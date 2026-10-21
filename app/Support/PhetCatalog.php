<?php

namespace App\Support;

/**
 * e-Lab catalog: Compasse's own hands-on practicals (titration, pendulum… run in the app, with
 * results tables) and curated PhET Interactive Simulations (University of Colorado Boulder),
 * embedded from PhET's site with PhET's attribution beside them.
 */
class PhetCatalog
{
    public const ATTRIBUTION = 'PhET Interactive Simulations, University of Colorado Boulder, https://phet.colorado.edu';

    /** slug => [title, subject, level] */
    private const SIMS = [
        // Physics
        'forces-and-motion-basics' => ['Forces and Motion: Basics', 'Physics', 'Primary / JSS'],
        'friction' => ['Friction', 'Physics', 'Primary / JSS'],
        'energy-skate-park-basics' => ['Energy Skate Park: Basics', 'Physics', 'JSS / SSS'],
        'energy-forms-and-changes' => ['Energy Forms and Changes', 'Physics', 'JSS'],
        'circuit-construction-kit-dc' => ['Circuit Construction Kit: DC', 'Physics', 'JSS / SSS'],
        'ohms-law' => ["Ohm's Law", 'Physics', 'SSS'],
        'faradays-law' => ["Faraday's Law", 'Physics', 'SSS'],
        'projectile-motion' => ['Projectile Motion', 'Physics', 'SSS'],
        'pendulum-lab' => ['Pendulum Lab', 'Physics', 'SSS'],
        'masses-and-springs' => ['Masses and Springs', 'Physics', 'SSS'],
        'hookes-law' => ["Hooke's Law", 'Physics', 'SSS'],
        'wave-on-a-string' => ['Wave on a String', 'Physics', 'SSS'],
        'geometric-optics' => ['Geometric Optics', 'Physics', 'SSS'],
        'gravity-and-orbits' => ['Gravity and Orbits', 'Physics', 'JSS / SSS'],
        'under-pressure' => ['Under Pressure', 'Physics', 'SSS'],
        'balancing-act' => ['Balancing Act', 'Physics', 'Primary / JSS'],
        'density' => ['Density', 'Physics', 'JSS'],
        'color-vision' => ['Color Vision', 'Physics', 'JSS'],
        'greenhouse-effect' => ['The Greenhouse Effect', 'Basic Science / Geography', 'JSS / SSS'],
        'bending-light' => ['Bending Light (refraction)', 'Physics', 'SSS'],
        'charges-and-fields' => ['Charges and Fields', 'Physics', 'SSS'],
        'coulombs-law' => ["Coulomb's Law", 'Physics', 'SSS'],
        'resistance-in-a-wire' => ['Resistance in a Wire', 'Physics', 'SSS'],
        'capacitor-lab-basics' => ['Capacitor Lab: Basics', 'Physics', 'SSS'],
        'wave-interference' => ['Wave Interference', 'Physics', 'SSS'],
        'waves-intro' => ['Waves Intro (sound, light, water)', 'Physics', 'JSS / SSS'],
        'collision-lab' => ['Collision Lab (momentum)', 'Physics', 'SSS'],
        'gas-properties' => ['Gas Properties (gas laws)', 'Physics / Chemistry', 'SSS'],
        'vector-addition' => ['Vector Addition', 'Physics / Further Mathematics', 'SSS'],
        'john-travoltage' => ['Static Electricity (John Travoltage)', 'Basic Science', 'Primary / JSS'],
        // Chemistry
        'build-an-atom' => ['Build an Atom', 'Chemistry', 'JSS / SSS'],
        'isotopes-and-atomic-mass' => ['Isotopes and Atomic Mass', 'Chemistry', 'SSS'],
        'states-of-matter-basics' => ['States of Matter: Basics', 'Chemistry', 'JSS'],
        'ph-scale' => ['pH Scale', 'Chemistry', 'SSS'],
        'acid-base-solutions' => ['Acid-Base Solutions', 'Chemistry', 'SSS'],
        'concentration' => ['Concentration', 'Chemistry', 'SSS'],
        'balancing-chemical-equations' => ['Balancing Chemical Equations', 'Chemistry', 'SSS'],
        'reactants-products-and-leftovers' => ['Reactants, Products and Leftovers', 'Chemistry', 'SSS'],
        'molecule-shapes' => ['Molecule Shapes', 'Chemistry', 'SSS'],
        'build-a-molecule' => ['Build a Molecule', 'Chemistry', 'JSS / SSS'],
        'molarity' => ['Molarity', 'Chemistry', 'SSS'],
        'beers-law-lab' => ["Beer's Law Lab (colour and concentration)", 'Chemistry', 'SSS'],
        'diffusion' => ['Diffusion', 'Chemistry / Biology', 'JSS / SSS'],
        'gases-intro' => ['Gases Intro', 'Chemistry', 'JSS / SSS'],
        'molecule-polarity' => ['Molecule Polarity', 'Chemistry', 'SSS'],
        // Biology
        'natural-selection' => ['Natural Selection', 'Biology', 'SSS'],
        'gene-expression-essentials' => ['Gene Expression Essentials', 'Biology', 'SSS'],
        'neuron' => ['Neuron (nerve impulse)', 'Biology', 'SSS'],
        // Mathematics
        'make-a-ten' => ['Make a Ten', 'Mathematics', 'Primary'],
        'arithmetic' => ['Arithmetic', 'Mathematics', 'Primary'],
        'fractions-intro' => ['Fractions: Intro', 'Mathematics', 'Primary'],
        'area-builder' => ['Area Builder', 'Mathematics', 'Primary'],
        'area-model-multiplication' => ['Area Model Multiplication', 'Mathematics', 'Primary'],
        'unit-rates' => ['Unit Rates', 'Mathematics', 'Primary / JSS'],
        'number-line-integers' => ['Number Line: Integers', 'Mathematics', 'JSS'],
        'equality-explorer' => ['Equality Explorer', 'Mathematics', 'JSS'],
        'function-builder' => ['Function Builder', 'Mathematics', 'JSS'],
        'graphing-lines' => ['Graphing Lines', 'Mathematics', 'JSS / SSS'],
        'graphing-quadratics' => ['Graphing Quadratics', 'Mathematics', 'SSS'],
        'graphing-slope-intercept' => ['Graphing Slope-Intercept', 'Mathematics', 'JSS / SSS'],
        'trig-tour' => ['Trig Tour (sine, cosine, tangent)', 'Mathematics', 'SSS'],
        'curve-fitting' => ['Curve Fitting', 'Further Mathematics', 'SSS'],
        'plinko-probability' => ['Plinko Probability', 'Mathematics', 'JSS / SSS'],
        'calculus-grapher' => ['Calculus Grapher (derivatives and integrals)', 'Further Mathematics', 'SSS'],
    ];

    /** slug => [title, subject, level, practical key in the app] — built into Compasse. */
    private const PRACTICALS = [
        'practical-titration' => ['Acid–base titration (practical)', 'Chemistry', 'SSS', 'titration'],
        'practical-qualitative-analysis' => ['Qualitative analysis — identify salt X (practical)', 'Chemistry', 'SSS', 'qualitative-analysis'],
        'practical-refraction' => ['Refraction through a glass block (practical)', 'Physics', 'SSS', 'refraction'],
        'practical-pendulum' => ['Simple pendulum — find g (practical)', 'Physics', 'SSS', 'pendulum'],
        'practical-ohms-law' => ["Ohm's law (practical)", 'Physics', 'JSS / SSS', 'ohms-law'],
        'practical-heart' => ['The heart & circulation (animation)', 'Biology', 'JSS / SSS', 'heart'],
        'practical-mitosis' => ['Mitosis — stages of cell division (animation)', 'Biology', 'SSS', 'mitosis'],
        'practical-osmosis' => ['Osmosis in potato strips (practical)', 'Biology', 'SSS', 'osmosis'],
        'practical-food-tests' => ['Food tests (practical)', 'Biology', 'JSS / SSS', 'food-tests'],
        'practical-vernier' => ['Vernier caliper & micrometer (practical)', 'Basic Technology', 'JSS / SSS', 'vernier'],
        'practical-logic-gates' => ['Logic gates & number bases', 'Computer Science', 'JSS / SSS', 'logic-gates'],
        'practical-parts-of-speech' => ['Parts of speech', 'English Language', 'Primary / JSS', 'parts-of-speech'],
        'practical-graph-plotter' => ['Graph plotter', 'Mathematics', 'JSS / SSS', 'graph-plotter'],
        'practical-calculus' => ['Calculus: tangents & areas', 'Further Mathematics', 'SSS', 'calculus'],
    ];

    public static function has(string $slug): bool
    {
        return isset(self::SIMS[$slug]) || isset(self::PRACTICALS[$slug]);
    }

    public static function find(string $slug): ?array
    {
        if (isset(self::PRACTICALS[$slug])) {
            [$title, $subject, $level, $key] = self::PRACTICALS[$slug];

            return ['slug' => $slug, 'type' => 'practical', 'practical' => $key, 'title' => $title, 'subject' => $subject, 'level' => $level,
                'embed_url' => null, 'page_url' => null, 'thumbnail' => null];
        }
        if (! isset(self::SIMS[$slug])) {
            return null;
        }
        [$title, $subject, $level] = self::SIMS[$slug];

        return [
            'slug' => $slug,
            'type' => 'phet',
            'title' => $title,
            'subject' => $subject,
            'level' => $level,
            'embed_url' => "https://phet.colorado.edu/sims/html/{$slug}/latest/{$slug}_all.html",
            'page_url' => "https://phet.colorado.edu/en/simulations/{$slug}",
            'thumbnail' => "https://phet.colorado.edu/sims/html/{$slug}/latest/{$slug}-600.png",
        ];
    }

    public static function all(): array
    {
        return array_map(fn ($slug) => self::find($slug), array_merge(array_keys(self::PRACTICALS), array_keys(self::SIMS)));
    }
}
