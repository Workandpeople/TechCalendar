import Quill, { Parchment } from 'quill/core';
import Bold from 'quill/formats/bold';
import Italic from 'quill/formats/italic';
import Underline from 'quill/formats/underline';
import Strike from 'quill/formats/strike';
import List from 'quill/formats/list';
import Blockquote from 'quill/formats/blockquote';
import { FontClass as Font } from 'quill/formats/font';
import { SizeStyle as Size } from 'quill/formats/size';
import { ColorAttributor } from 'quill/formats/color';
import Toolbar from 'quill/modules/toolbar';
import 'quill/dist/quill.core.css';
import formats from '../comment-formats.json' with { type: 'json' };
import { commentStyles } from './comment-markup.js';

Font.whitelist = Object.keys(formats.fonts);
Size.whitelist = formats.sizes;
class CommentColor extends ColorAttributor {
    canAdd(node, value) {
        // Browsers serialize colors as rgb(); accept the same palette in either representation.
        return commentStyles(`color: ${value}`) !== '' && super.canAdd(node, value);
    }
}
const Color = new CommentColor('color', 'color', { scope: Parchment.Scope.INLINE });
// No links, media or formula blots: only the formats our sanitizer understands.
Quill.register({ 'formats/bold': Bold, 'formats/italic': Italic, 'formats/underline': Underline,
    'formats/strike': Strike, 'formats/list': List, 'formats/blockquote': Blockquote,
    'formats/font': Font, 'formats/size': Size, 'formats/color': Color, 'modules/toolbar': Toolbar }, true);

export function createCommentEditor(surface, toolbar, placeholder) {
    const editor = new Quill(surface, {
        theme: null, placeholder,
        formats: ['bold', 'italic', 'underline', 'strike', 'font', 'size', 'color', 'list', 'blockquote'],
        modules: { toolbar, uploader: { mimetypes: [] }, history: { delay: 800, maxStack: 80, userOnly: true } },
    });
    return editor;
}
