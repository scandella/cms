<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\controllers;

use Craft;
use craft\base\Element;
use craft\elements\Category;
use craft\errors\InvalidElementException;
use craft\helpers\Cp;
use craft\helpers\ElementHelper;
use craft\helpers\UrlHelper;
use craft\models\CategoryGroup;
use craft\models\CategoryGroup_SiteSettings;
use craft\web\Controller;
use Throwable;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\web\ServerErrorHttpException;

/**
 * The CategoriesController class is a controller that handles various actions related to categories and category
 * groups, such as creating, editing and deleting them.
 * Note that all actions in the controller require an authenticated Craft session via [[allowAnonymous]].
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.0.0
 */
class CategoriesController extends Controller
{
    /**
     * @event ElementEvent The event that is triggered when a category’s template is rendered for Live Preview.
     */
    public const EVENT_PREVIEW_CATEGORY = 'previewCategory';

    /**
     * @inheritdoc
     */
    protected $allowAnonymous = ['view-shared-category'];

    // Category Groups
    // -------------------------------------------------------------------------

    /**
     * Category groups index.
     *
     * @return Response
     */
    public function actionGroupIndex(): Response
    {
        $this->requireAdmin();

        $groups = Craft::$app->getCategories()->getAllGroups();

        return $this->renderTemplate('settings/categories/index', [
            'categoryGroups' => $groups,
        ]);
    }

    /**
     * Edit a category group.
     *
     * @param int|null $groupId The category group’s ID, if editing an existing group.
     * @param CategoryGroup|null $categoryGroup The category group being edited, if there were any validation errors.
     * @return Response
     * @throws NotFoundHttpException if the requested category group cannot be found
     */
    public function actionEditCategoryGroup(?int $groupId = null, ?CategoryGroup $categoryGroup = null): Response
    {
        $this->requireAdmin();

        $variables = [];

        // Breadcrumbs
        $variables['crumbs'] = [
            [
                'label' => Craft::t('app', 'Settings'),
                'url' => UrlHelper::url('settings'),
            ],
            [
                'label' => Craft::t('app', 'Categories'),
                'url' => UrlHelper::url('settings/categories'),
            ],
        ];

        $variables['brandNewGroup'] = false;

        if ($groupId !== null) {
            if ($categoryGroup === null) {
                $categoryGroup = Craft::$app->getCategories()->getGroupById($groupId);

                if (!$categoryGroup) {
                    throw new NotFoundHttpException('Category group not found');
                }
            }

            $variables['title'] = trim($categoryGroup->name) ?: Craft::t('app', 'Edit Category Group');
        } else {
            if ($categoryGroup === null) {
                $categoryGroup = new CategoryGroup();
                $variables['brandNewGroup'] = true;
            }

            $variables['title'] = Craft::t('app', 'Create a new category group');
        }

        $variables['groupId'] = $groupId;
        $variables['categoryGroup'] = $categoryGroup;

        return $this->renderTemplate('settings/categories/_edit', $variables);
    }

    /**
     * Save a category group.
     *
     * @return Response|null
     * @throws BadRequestHttpException
     */
    public function actionSaveGroup(): ?Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();

        $categoriesService = Craft::$app->getCategories();
        $groupId = $this->request->getBodyParam('groupId');

        if ($groupId) {
            $group = $categoriesService->getGroupById($groupId);
            if (!$group) {
                throw new BadRequestHttpException("Invalid category group ID: $groupId");
            }
        } else {
            $group = new CategoryGroup();
        }

        // Main group settings
        $group->name = $this->request->getBodyParam('name');
        $group->handle = $this->request->getBodyParam('handle');
        $group->maxLevels = (int)$this->request->getBodyParam('maxLevels') ?: null;
        $group->defaultPlacement = $this->request->getBodyParam('defaultPlacement') ?? $group->defaultPlacement;

        // Site-specific settings
        $allSiteSettings = [];

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $postedSettings = $this->request->getBodyParam('sites.' . $site->handle);

            $siteSettings = new CategoryGroup_SiteSettings();
            $siteSettings->siteId = $site->id;

            if ($siteSettings->hasUrls = !empty($postedSettings['uriFormat'])) {
                $siteSettings->uriFormat = $postedSettings['uriFormat'];
                $siteSettings->template = $postedSettings['template'] ?? null;
            }

            $allSiteSettings[$site->id] = $siteSettings;
        }

        $group->setSiteSettings($allSiteSettings);

        // Group the field layout
        $fieldLayout = Craft::$app->getFields()->assembleLayoutFromPost();
        $fieldLayout->type = Category::class;
        $group->setFieldLayout($fieldLayout);

        // Save it
        if (!$categoriesService->saveGroup($group)) {
            $this->setFailFlash(Craft::t('app', 'Couldn’t save the category group.'));

            // Send the category group back to the template
            Craft::$app->getUrlManager()->setRouteParams([
                'categoryGroup' => $group,
            ]);

            return null;
        }

        $this->setSuccessFlash(Craft::t('app', 'Category group saved.'));
        return $this->redirectToPostedUrl($group);
    }

    /**
     * Deletes a category group.
     *
     * @return Response
     */
    public function actionDeleteCategoryGroup(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requireAdmin();

        $groupId = $this->request->getRequiredBodyParam('id');

        Craft::$app->getCategories()->deleteGroupById($groupId);

        return $this->asSuccess();
    }

    // Categories
    // -------------------------------------------------------------------------

    /**
     * Displays the category index page.
     *
     * @param string|null $groupHandle The category group’s handle.
     * @return Response
     * @throws ForbiddenHttpException if the user is not permitted to edit categories
     */
    public function actionCategoryIndex(?string $groupHandle = null): Response
    {
        $groups = Craft::$app->getCategories()->getEditableGroups();

        if (empty($groups)) {
            throw new ForbiddenHttpException('User not permitted to edit categories');
        }

        $this->view->registerTranslations('app', [
            'New category',
        ]);

        return $this->renderTemplate('categories/_index', [
            'groupHandle' => $groupHandle,
            'groups' => $groups,
        ]);
    }

    /**
     * Creates a new unpublished draft and redirects to its edit page.
     *
     * @param string $groupHandle The group’s handle
     * @return Response
     * @throws BadRequestHttpException
     * @throws ForbiddenHttpException
     * @throws ServerErrorHttpException
     * @since 4.0.0
     */
    public function actionCreate(string $groupHandle): Response
    {
        $group = Craft::$app->getCategories()->getGroupByHandle($groupHandle);
        if (!$group) {
            throw new BadRequestHttpException("Invalid category group handle: $groupHandle");
        }

        $site = Cp::requestedSite();

        if (!$site) {
            throw new ForbiddenHttpException('User not authorized to edit content in any sites.');
        }

        $user = Craft::$app->getUser()->getIdentity();

        // Create & populate the draft
        $category = Craft::createObject(Category::class);
        $category->siteId = $site->id;
        $category->groupId = $group->id;

        // Structure parent
        if ($group->maxLevels !== 1) {
            // Set the initially selected parent
            $category->setParentId($this->request->getParam('parentId'));
        }

        // Make sure the user is allowed to create this category
        if (!$category->canSave($user)) {
            throw new ForbiddenHttpException('User not authorized to save this category.');
        }

        // Title & slug
        $category->title = $this->request->getQueryParam('title');
        $category->slug = $this->request->getQueryParam('slug');
        if ($category->title && !$category->slug) {
            $category->slug = ElementHelper::generateSlug($category->title, null, $site->language);
        }
        if (!$category->slug) {
            $category->slug = ElementHelper::tempSlug();
        }

        // Save it
        $category->setScenario(Element::SCENARIO_ESSENTIALS);
        if (!Craft::$app->getDrafts()->saveElementAsDraft($category, Craft::$app->getUser()->getId(), null, null, false)) {
            throw new ServerErrorHttpException(sprintf('Unable to save category as a draft: %s', implode(', ', $category->getErrorSummary(true))));
        }

        // Set its position in the structure if a before/after parma was passed
        if ($nextId = $this->request->getParam('before')) {
            $nextCategory = Craft::$app->getEntries()->getCategoryById($nextId, $site->id, [
                'structureId' => $group->structureId,
            ]);
            Craft::$app->getStructures()->moveBefore($group->structureId, $category, $nextCategory);
        } else if ($prevId = $this->request->getParam('after')) {
            $prevCategory = Craft::$app->getEntries()->getCategoryById($prevId, $site->id, [
                'structureId' => $group->structureId,
            ]);
            Craft::$app->getStructures()->moveAfter($group->structureId, $category, $prevCategory);
        }

        // Redirect to its edit page
        return $this->redirect($category->getCpEditUrl());
    }

    /**
     * Saves an category.
     *
     * @return Response|null
     * @throws ServerErrorHttpException
     * @deprecated in 4.0.0
     */
    public function actionSaveCategory(): ?Response
    {
        $this->requirePostRequest();

        $category = $this->_getCategoryModel();
        $categoryVariable = $this->request->getValidatedBodyParam('categoryVariable') ?? 'category';

        // Permission enforcement
        $this->_enforceEditCategoryPermissions($category);

        // Are we duplicating the category?
        if ($this->request->getBodyParam('duplicate')) {
            // Swap $category with the duplicate
            try {
                $category = Craft::$app->getElements()->duplicateElement($category);
            } catch (InvalidElementException $e) {
                /** @var Category $clone */
                $clone = $e->element;

                if ($this->request->getAcceptsJson()) {
                    return $this->asModelFailure($clone);
                }

                // Send the original category back to the template, with any validation errors on the clone
                $category->addErrors($clone->getErrors());

                return $this->asModelFailure(
                    $category,
                    Craft::t('app', 'Couldn’t duplicate category.'),
                    'category'
                );
            } catch (Throwable $e) {
                throw new ServerErrorHttpException(Craft::t('app', 'An error occurred when duplicating the category.'), 0, $e);
            }
        }

        // Populate the category with post data
        $this->_populateCategoryModel($category);

        // Save the category
        if ($category->enabled && $category->getEnabledForSite()) {
            $category->setScenario(Element::SCENARIO_LIVE);
        }

        if (!Craft::$app->getElements()->saveElement($category)) {
            return $this->asModelFailure(
                $category,
                Craft::t('app', 'Couldn’t save category.'),
                $categoryVariable
            );
        }

        if ($this->request->getAcceptsJson()) {
            return $this->asModelSuccess(
                $category,
                Craft::t('app', '{type} saved.', [
                    'type' => Category::displayName(),
                ]),
                data: [
                    'id' => $category->id,
                    'title' => $category->title,
                    'slug' => $category->slug,
                    'status' => $category->getStatus(),
                    'url' => $category->getUrl(),
                    'cpEditUrl' => $category->getCpEditUrl(),
                ]
            );
        }
    }

    /**
     * Fetches or creates a Category.
     *
     * @return Category
     * @throws BadRequestHttpException if the requested category group doesn't exist
     * @throws NotFoundHttpException if the requested category cannot be found
     */
    private function _getCategoryModel(): Category
    {
        $categoryId = $this->request->getBodyParam('sourceId') ?? $this->request->getBodyParam('categoryId');
        $siteId = $this->request->getBodyParam('siteId');

        if ($categoryId) {
            $category = Craft::$app->getCategories()->getCategoryById($categoryId, $siteId);

            if (!$category) {
                throw new NotFoundHttpException('Category not found');
            }
        } else {
            $groupId = $this->request->getRequiredBodyParam('groupId');
            if (($group = Craft::$app->getCategories()->getGroupById($groupId)) === null) {
                throw new BadRequestHttpException('Invalid category group ID: ' . $groupId);
            }

            $category = new Category();
            $category->groupId = $group->id;
            $category->fieldLayoutId = $group->fieldLayoutId;

            if ($siteId) {
                $category->siteId = $siteId;
            }
        }

        return $category;
    }

    /**
     * Enforces all Edit Category permissions.
     *
     * @param Category $category
     */
    private function _enforceEditCategoryPermissions(Category $category): void
    {
        if (Craft::$app->getIsMultiSite()) {
            // Make sure they have access to this site
            $this->requirePermission('editSite:' . $category->getSite()->uid);
        }

        // Make sure the user is allowed to edit categories in this group
        $group = $category->getGroup();
        $this->requirePermission("saveCategories:$group->uid");
    }

    /**
     * Populates an Category with post data.
     *
     * @param Category $category
     */
    private function _populateCategoryModel(Category $category): void
    {
        // Set the category attributes, defaulting to the existing values for whatever is missing from the post data
        $category->slug = $this->request->getBodyParam('slug', $category->slug);
        $category->title = $this->request->getBodyParam('title', $category->title);

        $enabledForSite = $this->request->getBodyParam('enabledForSite');
        if (is_array($enabledForSite)) {
            // Make sure they are allowed to edit all of the posted site IDs
            $editableSiteIds = Craft::$app->getSites()->getEditableSiteIds();
            if (array_diff(array_keys($enabledForSite), $editableSiteIds)) {
                throw new ForbiddenHttpException('User not permitted to edit the statuses for all the submitted site IDs');
            }
            // Set the global status to true if it's enabled for *any* sites, or if already enabled.
            $category->enabled = in_array(true, $enabledForSite, false) || $category->enabled;
        } else {
            $category->enabled = (bool)$this->request->getBodyParam('enabled', $category->enabled);
        }
        $category->setEnabledForSite($enabledForSite ?? $category->getEnabledForSite());

        $fieldsLocation = $this->request->getParam('fieldsLocation', 'fields');
        $category->setFieldValuesFromRequest($fieldsLocation);

        // Parent
        if (($parentId = $this->request->getBodyParam('parentId')) !== null) {
            $category->setParentId($parentId);
        }
    }

    /**
     * Displays a category.
     *
     * @param Category $category
     * @return Response
     * @throws ServerErrorHttpException if the category doesn't have a URL for the site it's configured with, or if the category's site ID is invalid
     */
    private function _showCategory(Category $category): Response
    {
        $categoryGroupSiteSettings = $category->getGroup()->getSiteSettings();

        if (!isset($categoryGroupSiteSettings[$category->siteId]) || !$categoryGroupSiteSettings[$category->siteId]->hasUrls) {
            throw new ServerErrorHttpException('The category ' . $category->id . ' doesn’t have a URL for the site ' . $category->siteId . '.');
        }

        $site = Craft::$app->getSites()->getSiteById($category->siteId, true);

        if (!$site) {
            throw new ServerErrorHttpException('Invalid site ID: ' . $category->siteId);
        }

        Craft::$app->language = $site->language;
        Craft::$app->set('locale', Craft::$app->getI18n()->getLocaleById($site->language));

        // Have this category override any freshly queried categories with the same ID/site
        if ($category->id) {
            Craft::$app->getElements()->setPlaceholderElement($category);
        }

        $this->getView()->getTwig()->disableStrictVariables();

        return $this->renderTemplate($categoryGroupSiteSettings[$category->siteId]->template, [
            'category' => $category,
        ]);
    }

    /**
     * Returns the HTML for a Categories field input, based on a given list of selected category IDs.
     *
     * @return Response
     * @since 4.0.0
     */
    public function actionInputHtml(): Response
    {
        $this->requireAcceptsJson();

        $categoryIds = $this->request->getParam('categoryIds', []);

        /** @var Category[] $categories */
        $categories = [];

        if (!empty($categoryIds)) {
            $categories = Category::find()
                ->id($categoryIds)
                ->siteId($this->request->getParam('siteId'))
                ->status(null)
                ->all();

            // Fill in the gaps
            $structuresService = Craft::$app->getStructures();
            $structuresService->fillGapsInElements($categories);

            // Enforce the branch limit
            if ($branchLimit = $this->request->getParam('branchLimit')) {
                $structuresService->applyBranchLimitToElements($categories, $branchLimit);
            }
        }

        $html = $this->getView()->renderTemplate('_components/fieldtypes/Categories/input',
            [
                'elements' => $categories,
                'id' => $this->request->getParam('id'),
                'name' => $this->request->getParam('name'),
                'selectionLabel' => $this->request->getParam('selectionLabel'),
            ]);

        return $this->asJson([
            'html' => $html,
        ]);
    }
}
