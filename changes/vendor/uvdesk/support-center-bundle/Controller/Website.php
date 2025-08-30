<?php

namespace Webkul\UVDesk\SupportCenterBundle\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Webkul\UVDesk\CoreFrameworkBundle\Services\UserService;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Webkul\UVDesk\CoreFrameworkBundle\Entity as CoreEntities; 
use Webkul\UVDesk\SupportCenterBundle\Entity as SupportEntities;

class Website extends AbstractController
{
    private $visibility = ['public'];
    private $limit = 5;
    private $company;

    private $userService;
    private $translator;
    private $constructContainer;
    private $httpClient;

    public function __construct(UserService $userService, TranslatorInterface $translator, ContainerInterface $constructContainer, HttpClientInterface $httpClient)
    {
        $this->userService = $userService;
        $this->translator = $translator;
        $this->constructContainer = $constructContainer;
        $this->httpClient = $httpClient;
    }

    private function isKnowledgebaseActive()
    {
        $entityManager = $this->getDoctrine()->getManager();
        $website = $entityManager->getRepository(CoreEntities\Website::class)->findOneByCode('knowledgebase');

        if (! empty($website)) {
            $knowledgebaseWebsite = $entityManager->getRepository(SupportEntities\KnowledgebaseWebsite::class)->findOneBy(['website' => $website->getId(), 'status' => true]);

            if (! empty($knowledgebaseWebsite) && true == $knowledgebaseWebsite->getIsActive()) {
                return true;
            }
        }

        throw new NotFoundHttpException('Page Not Found');
    }

    public function home(Request $request)
    {
        $this->isKnowledgebaseActive();

        $parameterBag = [
            'visibility' => 'public',
            'sort'       => 'id',
            'direction'  => 'desc'
        ];

        $articleRepository = $this->getDoctrine()->getRepository(SupportEntities\Article::class);
        $solutionRepository = $this->getDoctrine()->getRepository(SupportEntities\Solutions::class);

        $twigResponse = [
            'searchDisable' => false,
            'popArticles'   => $articleRepository->getPopularTranslatedArticles($request->getLocale()),
            'solutions'     => $solutionRepository->getAllSolutions(new ParameterBag($parameterBag), $this->constructContainer, 'a', [1]),
        ];

        $newResult = [];
       
        foreach ($twigResponse['solutions'] as $key => $result) {
            $newResult[] = [
                'id'              => $result->getId(),
                'name'            => $result->getName(),
                'description'     => $result->getDescription(),
                'visibility'      => $result->getVisibility(),
                'solutionImage'   => ($result->getSolutionImage() == null) ? '' : $result->getSolutionImage(),
                'categoriesCount' => $solutionRepository->getCategoriesCountBySolution($result->getId()),
                'categories'      => $solutionRepository->getCategoriesWithCountBySolution($result->getId()),
                'articleCount'    => $solutionRepository->getArticlesCountBySolution($result->getId()),
            ];
        }

        $twigResponse['solutions']['results'] = $newResult;
        $twigResponse['solutions']['categories'] = array_map(function($category) use ($articleRepository) {
            $parameterBag = [
                'categoryId' => $category['id'],
                'status'     => 1,
                'sort'       => 'id',
                'limit'      => 10,
                'direction'  => 'desc'
            ];

            $article =  $articleRepository->getAllArticles(new ParameterBag($parameterBag), $this->constructContainer, 'a.id, a.name, a.slug, a.stared');
             
            return [
                'id'          => $category['id'],
                'name'        => $category['name'],
                'description' => $category['description'],
                'articles'    => $article
            ];
        }, $solutionRepository->getAllCategories(10, 2));

        return $this->render('@UVDeskSupportCenter//Knowledgebase//index.html.twig', $twigResponse);
    }

    public function listCategories(Request $request)
    {
        $this->isKnowledgebaseActive();

        $solutionRepository = $this->getDoctrine()->getRepository(SupportEntities\Solutions::class);
        $categoryCollection = $solutionRepository->getAllCategories(10, 4);
        
        return $this->render('@UVDeskSupportCenter/Knowledgebase/categoryListing.html.twig', [
            'categories'    => $categoryCollection,
            'categoryCount' => count($categoryCollection),
        ]);
    }

    public function viewFolder(Request $request)
    {
        $this->isKnowledgebaseActive();
        
        if (!$request->attributes->get('solution'))
            return $this->redirect($this->generateUrl('helpdesk_knowledgebase'));

        $filterArray = ['id' => $request->attributes->get('solution')];

        $solution = $this->getDoctrine()
                    ->getRepository(SupportEntities\Solutions::class)
                    ->findOneBy($filterArray);

        if (! $solution)
            $this->noResultFound();

        if ($solution->getVisibility() == 'private') 
            return $this->redirect($this->generateUrl('helpdesk_knowledgebase'));

        $breadcrumbs = [
            [
                'label' => $this->translator->trans('Support Center'),
                'url'   => $this->generateUrl('helpdesk_knowledgebase')
            ],
            [
                'label' => $solution->getName(),
                'url'   => '#'
            ],
        ];

        $testArray = [1, 2, 3, 4];
        foreach ($testArray as $test) {
            $categories[] = [
                'id'           => $test,
                'name'         => $test . " name",
                'articleCount' => $test . " articleCount",
            ];
        }

        return $this->render('@UVDeskSupportCenter//Knowledgebase//folder.html.twig', [
            'folder'        => $solution,
            'categoryCount' => $this->getDoctrine()
                ->getRepository(SupportEntities\Solutions::class)
                ->getCategoriesCountBySolution($solution->getId()),
            'categories'    => $this->getDoctrine()
                ->getRepository(SupportEntities\Solutions::class)
                ->getCategoriesWithCountBySolution($solution->getId()),
            'breadcrumbs'   => $breadcrumbs
        ]);
    }

    public function viewFolderArticle(Request $request)
    {
        $this->isKnowledgebaseActive();

        if (! $request->attributes->get('solution'))
            return $this->redirect($this->generateUrl('helpdesk_knowledgebase'));

        $filterArray = ['id' => $request->attributes->get('solution')];

        $solution = $this->getDoctrine()
                    ->getRepository(SupportEntities\Solutions::class)
                    ->findOneBy($filterArray);

        if (! $solution)
            $this->noResultFound();
            
        if ($solution->getVisibility() == 'private')
            return $this->redirect($this->generateUrl('helpdesk_knowledgebase'));

        $breadcrumbs = [
            [
                'label' => $this->translator->trans('Support Center'),
                'url'   => $this->generateUrl('helpdesk_knowledgebase')
            ],
            [
                'label' => $solution->getName(),
                'url'   => '#'
            ],
        ];

        $parameterBag = [
            'solutionId'  => $solution->getId(),
            'status'      => 1,
            'sort'        => 'id',
            'direction'   => 'desc'
        ];
        $article_data = [
            'folder'        => $solution,
            'articlesCount' => $this->getDoctrine()
                ->getRepository(SupportEntities\Solutions::class)
                ->getArticlesCountBySolution($solution->getId(), [1]),
            'articles'      => $this->getDoctrine()
                ->getRepository(SupportEntities\Article::class)
                ->getAllArticles(new ParameterBag($parameterBag), $this->constructContainer, 'a.id, a.name, a.slug, a.stared'),
            'breadcrumbs'   => $breadcrumbs,
        ];

        return $this->render('@UVDeskSupportCenter/Knowledgebase/folderArticle.html.twig', $article_data);
    }

    public function viewCategory(Request $request)
    {
        $this->isKnowledgebaseActive();

        if (!$request->attributes->get('category'))
            return $this->redirect($this->generateUrl('helpdesk_knowledgebase'));

        $filterArray = array(
                            'id'     => $request->attributes->get('category'),
                            'status' => 1,
                        );
       
        $category = $this->getDoctrine()
                    ->getRepository(SupportEntities\SolutionCategory::class)
                    ->findOneBy($filterArray);
    
        if (! $category)
            $this->noResultFound();

        $breadcrumbs = [
            [ 'label' => $this->translator->trans('Support Center'),'url' => $this->generateUrl('helpdesk_knowledgebase') ],
            [ 'label' => $category->getName(),'url' => '#' ],
        ];
        
        $parameterBag = [
            'categoryId' => $category->getId(),
            'status'     => 1,
            'sort'       => 'id',
            'direction'  => 'desc'
        ];

        $category_data=  array(
            'category'      => $category,
            'articlesCount' => $this->getDoctrine()
                                ->getRepository(SupportEntities\SolutionCategory::class)
                                ->getArticlesCountByCategory($category->getId(), [1]),
            'articles'      => $this->getDoctrine()
                                ->getRepository(SupportEntities\Article::class)
                                ->getAllArticles(new ParameterBag($parameterBag), $this->constructContainer, 'a.id, a.name, a.slug, a.stared'),
            'breadcrumbs'   => $breadcrumbs
        );

        return $this->render('@UVDeskSupportCenter/Knowledgebase/category.html.twig',$category_data);
    }
   
    public function viewArticle(Request $request)
    {
        $this->isKnowledgebaseActive();
       
        if (!$request->attributes->get('article') && !$request->attributes->get('slug')) {
            return $this->redirect($this->generateUrl('helpdesk_knowledgebase'));
        }

        $entityManager = $this->getDoctrine()->getManager();
        $user = $this->userService->getCurrentUser();
        $articleRepository = $entityManager->getRepository(SupportEntities\Article::class);

        if ($request->attributes->get('article')) {
            $article = $articleRepository->findOneBy(['status' => 1, 'id' => $request->attributes->get('article')]);
        } else {
            $article = $articleRepository->findOneBy(['status' => 1,'slug' => $request->attributes->get('slug')]);
        }
       
        if (empty($article)) {
            $this->noResultFound();
        }

        $article->setContent($article->getContent());
        $article->setViewed((int) $article->getViewed() + 1);
        
        // Log article view
        $articleViewLog = new SupportEntities\ArticleViewLog();
        $articleViewLog->setUser(($user != null && $user != 'anon.') ? $user : null);
        
        $articleViewLog->setArticle($article);
        $articleViewLog->setViewedAt(new \DateTime('now'));

        $entityManager->persist($article);
        $entityManager->persist($articleViewLog);
        $entityManager->flush();
        
        // Get article feedbacks
        $feedbacks = ['enabled' => false, 'submitted' => false, 'article' => $articleRepository->getArticleFeedbacks($article)];

        if (! empty($user) && $user != 'anon.') {
            $feedbacks['enabled'] = true;

            if (! empty($feedbacks['article']['collection']) && in_array($user->getId(), array_column($feedbacks['article']['collection'], 'user'))) {
                $feedbacks['submitted'] = true;
            }
        }

        // @TODO: App popular articles
        $article_details = [
            'article' => $article,
            'breadcrumbs' => [
                ['label' => $this->translator->trans('Support Center'), 'url' => $this->generateUrl('helpdesk_knowledgebase')],
                ['label' => $article->getName(), 'url' => '#']
            ],
            'dateAdded'       => $this->userService->convertToTimezone($article->getDateAdded()),
            'articleTags'     => $articleRepository->getTagsByArticle($article->getId()),
            'articleAuthor'   => $articleRepository->getArticleAuthorDetails($article->getId()),
            'relatedArticles' => $articleRepository->getAllRelatedByArticle(['locale' => $request->getLocale(), 'articleId' => $article->getId()], [1]),
            'popArticles'     => $articleRepository->getPopularTranslatedArticles($request->getLocale())
        ];

        return $this->render('@UVDeskSupportCenter/Knowledgebase/article.html.twig', $article_details);
    }

    public function searchKnowledgebase(Request $request)
    {
        $this->isKnowledgebaseActive();

        $searchQuery = $request->query->get('s');
        if (empty($searchQuery)) {
            return $this->redirect($this->generateUrl('helpdesk_knowledgebase'));
        }

        $articleCollection = $this->getDoctrine()->getRepository(SupportEntities\Article::class)->getArticleBySearch($request);

        return $this->render('@UVDeskSupportCenter/Knowledgebase/search.html.twig', [
            'search'   => $searchQuery,
            'articles' => $articleCollection,
        ]);
    }

    public function viewTaggedResources(Request $request)
    {
        $this->isKnowledgebaseActive();

        $tagQuery = $request->attributes->get('tag');
        if (empty($tagQuery)) {
            return $this->redirect($this->generateUrl('helpdesk_knowledgebase'));
        }

        $tagLabel = $request->attributes->get('name');
        $articleCollection = $this->getDoctrine()->getRepository(SupportEntities\Article::class)->getArticleByTags([$tagLabel]);

        return $this->render('@UVDeskSupportCenter/Knowledgebase/search.html.twig', [
            'articles' => $articleCollection,
            'search' => $tagLabel,
            'breadcrumbs' => [
                ['label' => $this->translator->trans('Support Center'), 'url' => $this->generateUrl('helpdesk_knowledgebase')],
                ['label' => $tagLabel, 'url' => '#'],
            ],
        ]);
    }

    public function rateArticle($articleId, Request $request)
    {
        $this->isKnowledgebaseActive();

        // @TODO: Refactor
            
        // if ($request->getMethod() != 'POST') {
        //     return $this->redirect($this->generateUrl('helpdesk_knowledgebase'));
        // }

        // $company = $this->getCompany();
        // $user = $this->userService->getCurrentUser();
        $response = ['code' => 404, 'content' => ['alertClass' => 'danger', 'alertMessage' => 'An unexpected error occurred. Please try again later.']];

        // if (!empty($user) && $user != 'anon.') {
        //     $entityManager = $this->getDoctrine()->getEntityManager();
        //     $article = $entityManager->getRepository('WebkulSupportCenterBundle:Article')->findOneBy(['id' => $articleId, 'companyId' => $company->getId()]);

        //     if (!empty($article)) {
        //         $providedFeedback = $request->request->get('feedback');

        //         if (!empty($providedFeedback) && in_array(strtolower($providedFeedback), ['positive', 'neagtive'])) {
        //             $isArticleHelpful = ('positive' == strtolower($providedFeedback)) ? true : false;
        //             $articleFeedback = $entityManager->getRepository('WebkulSupportCenterBundle:ArticleFeedback')->findOneBy(['article' => $article, 'ratedCustomer' => $user]);

        //             $response = ['code' => 200, 'content' => ['alertClass' => 'success', 'alertMessage' => 'Feedback saved successfully.']];

        //             if (empty($articleFeedback)) {
        //                 $articleFeedback = new \Webkul\SupportCenterBundle\Entity\ArticleFeedback();

        //                 // $articleBadge->setDescription('');
        //                 $articleFeedback->setIsHelpful($isArticleHelpful);
        //                 $articleFeedback->setArticle($article);
        //                 $articleFeedback->setRatedCustomer($user);
        //                 $articleFeedback->setCreatedAt(new \DateTime('now'));
        //             } else {
        //                 $articleFeedback->setIsHelpful($isArticleHelpful);
        //                 $response['content']['alertMessage'] = 'Feedback updated successfully.';
        //             }

        //             $entityManager->persist($articleFeedback);
        //             $entityManager->flush();
        //         } else {
        //             $response['content']['alertMessage'] = 'Invalid feedback provided.';
        //         }
        //     } else {
        //         $response['content']['alertMessage'] = 'Article not found.';
        //     }
        // } else {
        //     $response['content']['alertMessage'] = 'You need to login to your account before can perform this action.';
        // }

        return new Response(json_encode($response['content']), $response['code'], ['Content-Type: application/json']);
    }

    //Ito ung chatbot page
    public function chatbot(Request $request)
    {
        $this->isKnowledgebaseActive();

        // If it's a POST request, handle API call
        if ($request->getMethod() === 'POST') {
            return $this->handleChatAPI($request);
        }

        // If it's a GET request, render the template
        return $this->render('@UVDeskSupportCenter/Knowledgebase/chatbot.html.twig', [
            'searchDisable' => true,
        ]);
    }

    private function handleChatAPI(Request $request)
    {
        // Debug log to check if method is called
        error_log('ChatAPI method called');
        
        try {
            // Get the message from the request
            $message = $request->request->get('message');
            error_log('Message received: ' . $message);
            
            if (empty($message)) {
                error_log('Empty message error');
                return new Response(json_encode(['error' => 'Message is required']), 400, ['Content-Type' => 'application/json']);
            }

            // Try multiple ways to get the API key
            $apiKey = null;
            try {
                $apiKey = $this->getParameter('google_ai_api_key');
                error_log('API Key from parameter: ' . (!empty($apiKey) ? 'Found' : 'Not found'));
            } catch (\Exception $e) {
                error_log('Parameter error: ' . $e->getMessage());
            }
            
            if (empty($apiKey)) {
                $apiKey = $_ENV['GOOGLE_AI_API_KEY'] ?? null;
                error_log('API Key from _ENV: ' . (!empty($apiKey) ? 'Found' : 'Not found'));
            }
            
            if (empty($apiKey)) {
                $apiKey = getenv('GOOGLE_AI_API_KEY');
                error_log('API Key from getenv: ' . (!empty($apiKey) ? 'Found' : 'Not found'));
            }
            
            if (empty($apiKey)) {
                error_log('API key not configured error - all methods failed');
                return new Response(json_encode(['response' => 'API key not configured. Please check your .env file.']), 200, ['Content-Type' => 'application/json']);
            }

            // Try to use injected HTTP client, fallback to cURL if not available
            try {
                $httpClient = $this->httpClient;
                error_log('Using Symfony HTTP client');
            } catch (\Exception $e) {
                error_log('Symfony HTTP client not available, using cURL: ' . $e->getMessage());
                $httpClient = null;
            }
            
            // Use Gemini 2.0 Flash endpoint
            $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash-exp:generateContent?key=' . $apiKey;
            
            $payload = [
                'contents' => [
                    [
                        'parts' => [
                            [
                                'text' => "You are a helpful customer support assistant for a helpdesk system. Please provide a concise, professional, and helpful response (maximum 300 words) to the following question. Use simple formatting with **bold** for important points and numbered lists when appropriate: " . $message
                            ]
                        ]
                    ]
                ],
                'generationConfig' => [
                    'temperature' => 0.7,
                    'topK' => 40,
                    'topP' => 0.95,
                    'maxOutputTokens' => 512,
                ]
            ];

            error_log('Making API request to Google AI: ' . $url);
            error_log('Payload: ' . json_encode($payload));
            
            if ($httpClient !== null) {
                // Use Symfony HTTP client
                $response = $httpClient->request('POST', $url, [
                    'headers' => [
                        'Content-Type' => 'application/json',
                    ],
                    'json' => $payload,
                    'timeout' => 30
                ]);

                $statusCode = $response->getStatusCode();
                $responseContent = $response->getContent();
            } else {
                // Use cURL as fallback
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Content-Type: application/json',
                ]);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                
                $responseContent = curl_exec($ch);
                $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                curl_close($ch);
                
                if ($curlError) {
                    error_log('cURL Error: ' . $curlError);
                    return new Response(json_encode(['response' => 'Network error: ' . $curlError]), 200, ['Content-Type' => 'application/json']);
                }
            }

            error_log('HTTP Status Code: ' . $statusCode);
            error_log('Raw API Response: ' . $responseContent);
            
            $responseData = json_decode($responseContent, true);
            
            if ($statusCode !== 200) {
                error_log('API Error - Status: ' . $statusCode . ', Response: ' . $responseContent);
                return new Response(json_encode(['response' => 'API request failed with status ' . $statusCode . '. Please check your API key and quota.']), 200, ['Content-Type' => 'application/json']);
            }
            
            if (isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
                $aiResponse = $responseData['candidates'][0]['content']['parts'][0]['text'];
                error_log('AI Response successful: ' . substr($aiResponse, 0, 100) . '...');
                
                // Clean up and format the AI response
                $formattedResponse = $this->formatChatbotResponse($aiResponse);
                
                return new Response(json_encode(['response' => $formattedResponse]), 200, ['Content-Type' => 'application/json']);
            } else {
                error_log('No valid response from AI API. Full response: ' . json_encode($responseData));
                
                // Check for error in response
                if (isset($responseData['error'])) {
                    $errorMsg = $responseData['error']['message'] ?? 'Unknown API error';
                    error_log('API Error: ' . $errorMsg);
                    return new Response(json_encode(['response' => 'API Error: ' . $errorMsg]), 200, ['Content-Type' => 'application/json']);
                }
                
                // Fallback response if AI doesn't work properly
                return new Response(json_encode(['response' => 'I apologize, but I am currently unable to process your request. Please try again later or contact our support team for assistance.']), 200, ['Content-Type' => 'application/json']);
            }

        } catch (\Exception $e) {
            // Log the error (you might want to use a logger service)
            error_log('Chatbot API Error: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());
            
            // Return a friendly error message
            return new Response(json_encode(['response' => 'Exception: ' . $e->getMessage()]), 200, ['Content-Type' => 'application/json']);
        }
    }

    public function chatAPI(Request $request)
    {
        return $this->handleChatAPI($request);
    }

    private function formatChatbotResponse($text)
    {
        // Clean up the response text
        $formatted = $text;
        
        // Convert **bold** to <strong>
        $formatted = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $formatted);
        
        // Convert *italic* to <em>
        $formatted = preg_replace('/\*(.*?)\*/', '<em>$1</em>', $formatted);
        
        // Convert numbered lists
        $formatted = preg_replace('/^(\d+)\.\s/m', '<br>$1. ', $formatted);
        
        // Convert bullet points
        $formatted = preg_replace('/^\*\s/m', '<br>• ', $formatted);
        
        // Clean up multiple line breaks
        $formatted = preg_replace('/\n\s*\n/', '<br><br>', $formatted);
        $formatted = preg_replace('/\n/', '<br>', $formatted);
        
        // Remove excessive line breaks at the start
        $formatted = preg_replace('/^(<br>\s*)+/', '', $formatted);
        
        // Limit response length for better UX
        if (strlen($formatted) > 2000) {
            $formatted = substr($formatted, 0, 1950) . '...<br><br><em>Response truncated for readability.</em>';
        }
        
        // Clean up any HTML injection attempts (basic security)
        $formatted = strip_tags($formatted, '<strong><em><br><p><ul><li><ol>');
        
        return $formatted;
    }

    /**
     * If customer is playing with url and no result is found then what will happen
     * @return 
     */
    protected function noResultFound()
    {
        throw new NotFoundHttpException('Not Found!');
    }
}