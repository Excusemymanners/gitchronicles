<?php

namespace App\Enums;

/**
 * The kinds of diagrams drawn for a repository (Faza 7). The first four are built from the code
 * analysis and the clone's manifests (ArchitectureDiagramBuilder); "flow" diagrams are written
 * by Claude (FlowDiagramWriter) and exist only when AI is turned on.
 */
enum DiagramType: string
{
    /** C4 level 1: who uses the system and which external systems it talks to. */
    case Context = 'context';

    /** C4 level 2: the parts that run separately (applications, databases) and their technologies. */
    case Container = 'container';

    /** C4 level 3: the biggest modules of the code, their main classes and dependencies. */
    case Component = 'component';

    /** The modules grouped in layers (entry, interface, web, logic, data) by folder conventions. */
    case Layers = 'layers';

    /** „Cum funcționează”: one main flow of the application, explained by Claude. */
    case Flow = 'flow';
}
