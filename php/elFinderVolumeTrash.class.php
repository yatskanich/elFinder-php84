<?php

/**
 * elFinder driver for trash bin at local filesystem.
 *
 * @author NaokiSawada
 **/
class elFinderVolumeTrash extends elFinderVolumeLocalFileSystem
{
    /**
     * Driver id
     * Must be started from letter and contains [a-z0-9]
     * Used as part of volume id.
     *
     * @var string
     **/
    protected $driverId = 't';

    public function __construct()
    {
        parent::__construct();
        // original option of the Trash
        $this->options['lockEverything'] = false; // Lock all items in the trash to disable delete, move, rename.

        // common options as the volume driver
        $this->options['alias'] = 'Trash';
        $this->options['quarantine'] = '';
        $this->options['rootCssClass'] = 'elfinder-navbar-root-trash';
        $this->options['copyOverwrite'] = false;
        $this->options['uiCmdMap'] = ['paste' => 'hidden', 'mkdir' => 'hidden', 'copy' => 'restore'];
        $this->options['disabled'] = [
            'archive',
            'duplicate',
            'edit',
            'extract',
            'mkfile',
            'places',
            'put',
            'rename',
            'resize',
            'upload'
        ];
    }

    #[\Override]
    public function mount(array $opts)
    {
        if ($this->options['lockEverything']) {
            if (!is_array($opts['attributes'])) {
                $opts['attributes'] = [];
            }
            $attr = [
                'pattern' => '/./',
                'locked' => true,
            ];
            array_unshift($opts['attributes'], $attr);
        }
        // force set `copyJoin` to true
        $opts['copyJoin'] = true;

        return parent::mount($opts);
    }
}
